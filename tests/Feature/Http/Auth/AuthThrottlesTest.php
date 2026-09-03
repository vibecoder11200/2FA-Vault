<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * AuthThrottlesTest (A11): throttles on previously-unthrottled
 * auth-adjacent routes.
 */
class AuthThrottlesTest extends FeatureTestCase
{
    private const PASSWORD = 'password';

    #[Test]
    public function test_pat_listing_is_throttled_to_10_per_minute()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web-guard');

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/oauth/personal-access-tokens')->assertStatus(200);
        }

        $this->getJson('/oauth/personal-access-tokens')->assertStatus(429);
    }

    #[Test]
    public function test_password_change_is_throttled_to_5_per_minute()
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        $this->actingAs($user, 'web-guard');

        for ($i = 0; $i < 5; $i++) {
            // Wrong current password: 400 responses, but they still count
            // against the throttle.
            $this->json('PATCH', '/user/password', [
                'currentPassword' => 'wrongpassword',
                'password'        => 'newpassword1',
                'password_confirmation' => 'newpassword1',
            ])->assertStatus(400);
        }

        $this->json('PATCH', '/user/password', [
            'currentPassword' => self::PASSWORD,
            'password'        => 'newpassword1',
            'password_confirmation' => 'newpassword1',
        ])->assertStatus(429);
    }

    #[Test]
    public function test_refresh_csrf_is_throttled_to_30_per_minute()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web-guard');

        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/refresh-csrf')->assertStatus(200);
        }

        $this->getJson('/refresh-csrf')->assertStatus(429);
    }
}
