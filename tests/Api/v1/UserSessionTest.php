<?php

namespace Tests\Api\v1;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UserSessionTest test class
 */
#[CoversClass(\App\Api\v1\Controllers\UserSessionController::class)]
class UserSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seed a Passport token row + matching UserSession for a user.
     * The token id is a fixed string used on BOTH rows so the controller's
     * JOIN (user_sessions.token_id = oauth_access_tokens.id) resolves.
     */
    private function seedSession(User $user, array $overrides = []) : UserSession
    {
        $tokenId = $overrides['token_id'] ?? 'token-' . uniqid();

        DB::table('oauth_access_tokens')->insert([
            'id'         => $tokenId,
            'user_id'    => $user->id,
            'client_id'  => 1,
            'name'       => 'Test',
            'scopes'     => '[]',
            'revoked'    => $overrides['revoked'] ?? false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        return UserSession::create([
            'user_id'        => $user->id,
            'token_id'       => $tokenId,
            'ip_address'     => '127.0.0.1',
            'user_agent'     => 'TestAgent',
            'last_active_at' => now(),
        ]);
    }

    #[Test]
    public function test_unauthenticated_request_returns_401()
    {
        $this->getJson('/api/v1/user/sessions')->assertUnauthorized();
        $this->deleteJson('/api/v1/user/sessions/1')->assertUnauthorized();
    }

    #[Test]
    public function test_authenticated_user_can_list_active_sessions()
    {
        $user = User::factory()->create();
        $this->seedSession($user);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson('/api/v1/user/sessions')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['id', 'token_id', 'ip_address', 'user_agent', 'last_active_at', 'created_at']],
            ]);
    }

    #[Test]
    public function test_revoked_tokens_are_excluded_from_list()
    {
        $user    = User::factory()->create();
        $session = $this->seedSession($user, ['revoked' => true]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/user/sessions');

        $response->assertStatus(200)->assertJsonMissing(['id' => $session->id]);
    }

    #[Test]
    public function test_user_can_revoke_own_session()
    {
        $user    = User::factory()->create();
        $session = $this->seedSession($user);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson('/api/v1/user/sessions/' . $session->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('user_sessions', ['id' => $session->id]);
        $this->assertSame(1, DB::table('oauth_access_tokens')->where('id', $session->token_id)->value('revoked'));
    }

    #[Test]
    public function test_user_cannot_revoke_other_users_session()
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $session  = $this->seedSession($owner);

        Passport::actingAs($intruder, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson('/api/v1/user/sessions/' . $session->id)
            ->assertNotFound();
    }
}
