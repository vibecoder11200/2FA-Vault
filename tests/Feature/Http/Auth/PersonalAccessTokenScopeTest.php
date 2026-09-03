<?php

namespace Tests\Feature\Http\Auth;

use App\Http\Controllers\Auth\PersonalAccessTokenController;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * PersonalAccessTokenScopeTest (A6 / RT1): new PATs require explicit scopes,
 * the legacy_full_access marker is the only grandfathering path, and the
 * endpoint scope matrix is enforced.
 */
#[CoversClass(PersonalAccessTokenController::class)]
#[CoversMethod(\App\Http\Middleware\EnsureTokenScope::class, 'handle')]
#[CoversMethod(AppServiceProvider::class, 'boot')]
class PersonalAccessTokenScopeTest extends FeatureTestCase
{
    #[Test]
    public function test_new_pat_with_omitted_scopes_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web-guard')
            ->json('POST', '/oauth/personal-access-tokens', [
                'name' => 'extension',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scopes']);
    }

    #[Test]
    public function test_new_pat_with_invalid_scope_is_rejected()
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web-guard')
            ->json('POST', '/oauth/personal-access-tokens', [
                'name'   => 'extension',
                'scopes' => ['read', 'godmode'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scopes']);
    }

    #[Test]
    public function test_new_pat_with_valid_scopes_is_created()
    {
        Artisan::call('passport:keys', ['--no-interaction' => 1]);
        app(\Laravel\Passport\ClientRepository::class)
            ->createPersonalAccessGrantClient(config('app.name'), 'users');

        $user = User::factory()->create();

        $response = $this->actingAs($user, 'web-guard')
            ->json('POST', '/oauth/personal-access-tokens', [
                'name'   => 'extension',
                'scopes' => ['read', 'otp'],
            ])
            ->assertStatus(200);

        $this->assertNotEmpty($response->json('accessToken'));
    }

    #[Test]
    public function test_legacy_marker_token_passes_read_and_write_routes()
    {
        $user  = User::factory()->create();
        $vault = \App\Models\Vault::factory()->create(['user_id' => $user->id]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');

        // Read path
        $this->getJson('/api/v1/twofaccounts')->assertOk();
        // Real destructive path: the legacy marker must keep extension/CLI
        // PATs working on destructive AND create/update routes.
        $this->deleteJson('/api/v1/vaults/' . $vault->id)->assertStatus(204);
        $this->postJson('/api/v1/twofaccounts', ['service' => 'legacy'])->assertStatus(422); // scope passed, payload invalid
    }

    #[Test]
    public function test_scoped_token_without_write_is_blocked_from_destructive_route()
    {
        $user    = User::factory()->create();
        $account = \App\Models\TwoFAccount::factory()->create(['user_id' => $user->id]);

        Passport::actingAs($user, ['read', 'otp'], 'api-guard');

        // Read path allowed
        $this->getJson('/api/v1/twofaccounts')->assertOk();
        // Destructive path forbidden
        $this->deleteJson('/api/v1/vaults/999')->assertForbidden();
        // Preferences rewrite forbidden
        $this->json('PUT', '/api/v1/user/preferences/showTokenAsDot', ['value' => true])->assertForbidden();
        // Create and update routes are write-scoped too
        $this->postJson('/api/v1/twofaccounts', ['service' => 'x'])->assertForbidden();
        $this->patchJson('/api/v1/twofaccounts/' . $account->id . '/counter', ['counter' => 1])->assertForbidden();
        $this->json('PUT', '/api/v1/webhooks/1', [])->assertForbidden();
    }

    #[Test]
    public function test_otp_only_token_is_blocked_from_identity_and_export_routes()
    {
        // Follow-up tightening: identity (GET /user returns the email) and the
        // data-export surfaces require the read scope — an otp-only PAT must
        // not be able to read them through the group's read,otp OR-semantics.
        $user = User::factory()->create();
        $account = \App\Models\TwoFAccount::factory()->create(['user_id' => $user->id]);

        Passport::actingAs($user, ['otp'], 'api-guard');

        // otp path still allowed
        $this->getJson('/api/v1/twofaccounts')->assertOk();
        // identity + exports forbidden
        $this->getJson('/api/v1/user')->assertForbidden();
        $this->getJson('/api/v1/twofaccounts/export?ids=' . $account->id)->assertForbidden();
        $this->postJson('/api/v1/backups/export')->assertForbidden();
        $this->getJson('/api/v1/backups/info')->assertForbidden();

        // A read token keeps full access to the same routes.
        Passport::actingAs($user, ['read'], 'api-guard');
        $this->getJson('/api/v1/user')->assertOk();
        $this->getJson('/api/v1/twofaccounts/export?ids=' . $account->id)->assertOk();
        $this->getJson('/api/v1/backups/info')->assertOk();
    }

    #[Test]
    public function test_scoped_token_without_admin_is_blocked_from_admin_route()
    {
        $admin = User::factory()->administrator()->create();

        Passport::actingAs($admin, ['read'], 'api-guard');

        $this->getJson('/api/v1/users')->assertForbidden();

        // The same admin with the legacy marker passes the admin gate.
        Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');
        $this->getJson('/api/v1/users')->assertOk();
    }

    #[Test]
    public function test_unscoped_token_has_no_access_at_all()
    {
        // RT1: a plain empty scope set must NOT be treated as legacy full
        // access — only the explicit legacy_full_access marker is.
        $user = User::factory()->create();

        Passport::actingAs($user, [], 'api-guard');

        $this->getJson('/api/v1/twofaccounts')->assertForbidden();
    }
}
