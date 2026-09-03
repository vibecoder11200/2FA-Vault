<?php

namespace Tests\Feature\Http\Auth;

use App\Models\User;
use App\Models\UserSession;
use App\Services\CredentialRevocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * PasswordRevocationTest (A2): password change/reset must revoke PATs,
 * delete user_sessions rows and re-arm vault_locked.
 */
#[CoversClass(CredentialRevocationService::class)]
class PasswordRevocationTest extends FeatureTestCase
{
    private const PASSWORD = 'password';

    /**
     * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'password'           => self::PASSWORD,
            'encryption_enabled' => true,
            'encryption_version' => 1,
            'vault_locked'       => false,
        ]);
    }

    /**
     * Seed a live PAT + a web-guard session for the user.
     */
    private function seedCredentials(User $user) : void
    {
        DB::table('oauth_access_tokens')->insert([
            'id'         => 'pat-' . uniqid(),
            'user_id'    => $user->id,
            'client_id'  => 1,
            'name'       => 'extension',
            'scopes'     => '["legacy_full_access"]',
            'revoked'    => false,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        UserSession::create([
            'user_id'        => $user->id,
            'token_id'       => 'web-session-' . uniqid(),
            'ip_address'     => '127.0.0.1',
            'user_agent'     => 'TestAgent',
            'last_active_at' => now(),
        ]);
    }

    private function assertAllCredentialsRevoked(User $user) : void
    {
        $this->assertSame(
            0,
            DB::table('oauth_access_tokens')->where('user_id', $user->id)->where('revoked', false)->count(),
            'All Passport tokens should be revoked'
        );
        $this->assertSame(0, $user->sessions()->count(), 'user_sessions rows should be deleted');
        $this->assertTrue($user->refresh()->vault_locked, 'vault_locked should be re-armed');
    }

    #[Test]
    public function test_self_password_change_revokes_all_credentials()
    {
        $this->seedCredentials($this->user);

        $this->actingAs($this->user, 'web-guard')
            ->json('PATCH', '/user/password', [
                'currentPassword' => self::PASSWORD,
                'password'        => 'newpassword',
                'password_confirmation' => 'newpassword',
            ])
            ->assertOk();

        $this->assertAllCredentialsRevoked($this->user);
    }

    #[Test]
    public function test_self_password_reset_revokes_all_credentials()
    {
        Notification::fake();

        $this->seedCredentials($this->user);
        $token = Password::broker()->createToken($this->user);

        $this->json('POST', '/user/password/reset', [
            'email'                 => $this->user->email,
            'password'              => 'newpassword',
            'password_confirmation' => 'newpassword',
            'token'                 => $token,
        ])->assertOk();

        $this->assertAllCredentialsRevoked($this->user);
    }

    #[Test]
    public function test_admin_password_reset_revokes_all_credentials()
    {
        $admin = User::factory()->administrator()->create();
        $this->seedCredentials($this->user);

        \Laravel\Passport\Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');

        Notification::fake();

        $this->json('PATCH', '/api/v1/users/' . $this->user->id . '/password/reset')->assertOk();

        $this->assertAllCredentialsRevoked($this->user);
    }
}
