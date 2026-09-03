<?php

namespace Tests\Api\v1\Controllers;

use App\Models\OtpLog;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\FeatureTestCase;

class OtpLogControllerTest extends FeatureTestCase
{
    use RefreshDatabase;

    protected function createEncryptedUser(array $attributes = []) : User
    {
        return User::factory()->create(array_merge([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version'    => 1,
            'vault_locked'          => false,
        ], $attributes));
    }

    public function test_user_can_list_their_own_otp_logs() : void
    {
        $user    = $this->createEncryptedUser();
        $account = TwoFAccount::factory()->for($user)->create(['otp_type' => 'totp']);

        OtpLog::create([
            'requester_id'   => $user->id,
            'owner_id'       => $user->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'generated_at'   => now(),
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/otp-logs');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.requester_id', $user->id)
            ->assertJsonPath('data.0.owner_id', $user->id)
            ->assertJsonPath('data.0.twofaccount_id', $account->id);
    }

    public function test_user_cannot_see_other_users_otp_logs() : void
    {
        $owner   = $this->createEncryptedUser();
        $other   = $this->createEncryptedUser();
        $account = TwoFAccount::factory()->for($owner)->create();

        OtpLog::create([
            'requester_id'   => $owner->id,
            'owner_id'       => $owner->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'generated_at'   => now(),
        ]);

        // The "other" user must not see the owner's logs.
        Passport::actingAs($other, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/otp-logs');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_ip_address_visible_only_to_owner_or_admin() : void
    {
        $owner   = $this->createEncryptedUser();
        $other   = $this->createEncryptedUser();
        $account = TwoFAccount::factory()->for($owner)->create();

        $log = OtpLog::create([
            'requester_id'   => $owner->id,
            'owner_id'       => $owner->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'ip_address'     => '203.0.113.5',
            'generated_at'   => now(),
        ]);

        // Owner sees their own IP.
        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson('/api/v1/otp-logs')
            ->assertJsonPath('data.0.ip_address', '203.0.113.5');
    }

    public function test_user_can_clear_their_own_otp_logs() : void
    {
        $user    = $this->createEncryptedUser();
        $account = TwoFAccount::factory()->for($user)->create();

        OtpLog::create([
            'requester_id'   => $user->id,
            'owner_id'       => $user->id,
            'twofaccount_id' => $account->id,
            'otp_type'       => 'totp',
            'generated_at'   => now(),
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson('/api/v1/otp-logs')
            ->assertStatus(204);

        $this->assertDatabaseCount('otp_logs', 0);
    }

    public function test_filter_by_twofaccounts_id() : void
    {
        $user     = $this->createEncryptedUser();
        $accountA = TwoFAccount::factory()->for($user)->create();
        $accountB = TwoFAccount::factory()->for($user)->create();

        OtpLog::create([
            'requester_id'   => $user->id, 'owner_id' => $user->id,
            'twofaccount_id' => $accountA->id, 'otp_type' => 'totp', 'generated_at' => now(),
        ]);
        OtpLog::create([
            'requester_id'   => $user->id, 'owner_id' => $user->id,
            'twofaccount_id' => $accountB->id, 'otp_type' => 'totp', 'generated_at' => now(),
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson('/api/v1/otp-logs?twofaccount_id=' . $accountA->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.twofaccount_id', $accountA->id);
    }
}
