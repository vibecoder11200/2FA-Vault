<?php

namespace Tests\Feature\Http\Middlewares;

use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * EnsureUserIsActiveTest test class (A1)
 */
#[CoversClass(EnsureUserIsActive::class)]
class EnsureUserIsActiveTest extends FeatureTestCase
{
    #[Test]
    public function test_deactivated_user_api_request_returns_401()
    {
        $user = User::factory()->create(['is_active' => false]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/twofaccounts')->assertUnauthorized();
    }

    #[Test]
    public function test_active_user_api_request_passes()
    {
        $user = User::factory()->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/twofaccounts')->assertOk();
    }

    #[Test]
    public function test_deactivated_user_web_session_is_logged_out()
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user, 'web-guard')
            ->getJson('/oauth/personal-access-tokens')
            ->assertUnauthorized();

        $this->assertGuest('web-guard');
    }

    #[Test]
    public function test_deactivated_user_cannot_log_back_in()
    {
        $user = User::factory()->create([
            'is_active' => false,
            'password'  => 'password',
        ]);

        $this->json('POST', '/user/login', [
            'email'    => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();

        $this->assertGuest('web-guard');
    }

    #[Test]
    public function test_deactivated_user_pat_is_rejected_at_the_guard_level()
    {
        $user = User::factory()->create(['is_active' => false]);

        Artisan::call('passport:keys', ['--no-interaction' => 1]);
        app(\Laravel\Passport\ClientRepository::class)
            ->createPersonalAccessGrantClient(config('app.name'), 'users');
        // Any valid scope works: the rejection happens at the guard/middleware
        // level, before scope checks. legacy_full_access itself is NOT a
        // mintable scope (it only comes from the cutover migration).
        $token = $user->createToken('extension', ['read'])->accessToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/twofaccounts')
            ->assertUnauthorized();
    }
}
