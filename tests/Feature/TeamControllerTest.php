<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;
use Tests\TestCase;

class TeamControllerTest extends TestCase
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

    /**
     * Test user can create a team
     */
    public function test_user_can_create_team()
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/teams', [
            'name' => 'Development Team',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'name',
                'owner_id',
                'created_at',
            ]);

        $this->assertDatabaseHas('teams', [
            'name'     => 'Development Team',
            'owner_id' => $user->id,
        ]);

        // Check owner is automatically added as member
        $this->assertDatabaseHas('team_users', [
            'team_id' => $response->json('id'),
            'user_id' => $user->id,
            'role'    => 'owner',
        ]);
    }

    /**
     * Test user can list teams
     */
    public function test_user_can_list_teams()
    {
        $user = $this->createEncryptedUser();

        // Create teams where user is member
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();
        $team3 = Team::factory()->create(); // User is not member

        $team1->users()->attach($user->id, ['role' => 'owner', 'joined_at' => now()]);
        $team2->users()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/teams');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $team1->id])
            ->assertJsonFragment(['id' => $team2->id]);
    }

    /**
     * Test user can invite to team
     */
    public function test_user_can_invite_to_team()
    {
        $owner   = $this->createEncryptedUser();
        $invitee = $this->createEncryptedUser();

        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/teams/{$team->id}/invite", [
            'email' => $invitee->email,
            'role'  => 'member',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email'   => $invitee->email,
            'role'    => 'member',
            'status'  => 'pending',
        ]);
    }

    /**
     * Test user can join team via invite
     */
    public function test_user_can_join_team_via_invite()
    {
        $owner   = $this->createEncryptedUser();
        $invitee = $this->createEncryptedUser();

        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'email'      => $invitee->email,
            'role'       => 'member',
            'token'      => \Str::random(32),
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Passport::actingAs($invitee, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/teams/invitations/{$invitation->token}/accept");

        $response->assertStatus(200);

        $this->assertDatabaseHas('team_users', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role'    => 'member',
        ]);

        $this->assertDatabaseHas('team_invitations', [
            'id'     => $invitation->id,
            'status' => 'accepted',
        ]);
    }

    /**
     * Test user can leave team
     */
    public function test_user_can_leave_team()
    {
        $owner  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();

        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        Passport::actingAs($member, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/teams/{$team->id}/leave");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('team_users', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
    }

    /**
     * Test owner can delete team
     */
    public function test_owner_can_delete_team()
    {
        $owner = $this->createEncryptedUser();

        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this->deleteJson("/api/v1/teams/{$team->id}");

        $response->assertStatus(200);

        // With soft deletes, record still exists but deleted_at is set
        $this->assertSoftDeleted('teams', [
            'id' => $team->id,
        ]);
    }

    /**
     * Test admin can remove member
     */
    public function test_admin_can_remove_member()
    {
        $owner  = $this->createEncryptedUser();
        $admin  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();

        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');
        $response = $this->deleteJson("/api/v1/teams/{$team->id}/members/{$member->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('team_users', [
            'team_id' => $team->id,
            'user_id' => $member->id,
        ]);
    }

    /**
     * Test viewer cannot update team
     */
    public function test_viewer_cannot_update_team()
    {
        $owner  = $this->createEncryptedUser();
        $viewer = $this->createEncryptedUser();

        $team = Team::factory()->create(['owner_id' => $owner->id]);

        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($viewer->id, ['role' => 'viewer', 'joined_at' => now()]);

        Passport::actingAs($viewer, ['legacy_full_access'], 'api-guard');
        $response = $this->putJson("/api/v1/teams/{$team->id}", [
            'name' => 'Updated Team Name',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('teams', [
            'id'   => $team->id,
            'name' => $team->name, // Name unchanged
        ]);
    }

    /**
     * Test unauthorized user cannot access team
     */
    public function test_unauthorized_user_cannot_access_team()
    {
        $owner        = User::factory()->create();
        $unauthorized = User::factory()->create();

        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        Passport::actingAs($unauthorized, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson("/api/v1/teams/{$team->id}");

        $response->assertStatus(403);
    }

    /**
     * Team ownership transfer: owner can transfer, becomes admin.
     */
    public function test_owner_can_transfer_team_ownership() : void
    {
        $owner  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();

        $team = Team::create([
            'name'        => 'Transfer Team',
            'owner_id'    => $owner->id,
            'invite_code' => \Illuminate\Support\Str::random(16),
        ]);
        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/teams/{$team->id}/transfer", [
            'new_owner_id' => $member->id,
        ]);

        $response->assertStatus(200);
        $team->refresh();
        $this->assertSame($member->id, $team->owner_id);
        $this->assertSame('owner', $team->getUserRole($member->id));
        $this->assertSame('admin', $team->getUserRole($owner->id));
    }

    /**
     * Non-owner cannot transfer team ownership.
     */
    public function test_non_owner_cannot_transfer_team_ownership() : void
    {
        $owner  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();
        $other  = $this->createEncryptedUser();

        $team = Team::create([
            'name'        => 'Blocked Team',
            'owner_id'    => $owner->id,
            'invite_code' => \Illuminate\Support\Str::random(16),
        ]);
        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);
        $team->users()->attach($other->id, ['role' => 'member', 'joined_at' => now()]);

        Passport::actingAs($member, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/teams/{$team->id}/transfer", [
            'new_owner_id' => $other->id,
        ]);

        $response->assertStatus(403);
    }

    // ── B1/B3/B4/RT7/B9: sharing correctness ─────────────────────────────

    protected function makeTeamWithMember(User $owner, User $member) : Team
    {
        $team = Team::create([
            'name'        => 'Test Team',
            'owner_id'    => $owner->id,
            'invite_code' => \Illuminate\Support\Str::random(16),
        ]);
        $team->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        return $team;
    }

    /**
     * B1: sharing with a real twofaccount id must succeed (the UI previously
     * sent twofaccount_id=0, which always failed validation with 422).
     */
    public function test_share_encrypted_with_real_account_id_succeeds() : void
    {
        $owner  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();
        $team   = $this->makeTeamWithMember($owner, $member);

        $account = \App\Models\TwoFAccount::factory()->create([
            'user_id'   => $owner->id,
            'encrypted' => true,
            'secret'    => json_encode(['ciphertext' => base64_encode('c'), 'iv' => base64_encode('i'), 'authTag' => base64_encode('t')]),
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/teams/{$team->id}/share-encrypted", [
            'twofaccount_id' => $account->id,
            'access_level'   => 'read',
            'member_keys'    => [
                ['member_id' => $member->id, 'wrapped_key' => 'wrapped-for-member'],
            ],
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('shared_accounts', [
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'member_id'      => $member->id,
            'wrapped_key'    => 'wrapped-for-member',
        ]);
    }

    /**
     * B3: removing a member revokes their shares and cancels their pending
     * invitations; the removed member cannot re-accept an old invite.
     */
    public function test_removed_member_loses_shared_access_and_cannot_rejoin() : void
    {
        $owner  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();
        $team   = $this->makeTeamWithMember($owner, $member);

        $account = \App\Models\TwoFAccount::factory()->create([
            'user_id' => $owner->id,
        ]);

        \App\Models\SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $owner->id,
            'member_id'      => $member->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped-for-member',
        ]);

        $invitation = TeamInvitation::create([
            'team_id'    => $team->id,
            'email'      => $member->email,
            'role'       => 'member',
            'token'      => \Str::random(32),
            'status'     => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson("/api/v1/teams/{$team->id}/members/{$member->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('shared_accounts', [
            'team_id'   => $team->id,
            'member_id' => $member->id,
        ]);

        $this->assertDatabaseHas('team_invitations', [
            'id'     => $invitation->id,
            'status' => 'cancelled',
        ]);

        // Belt-and-braces: even a surviving shared row must not grant view
        // once team membership is gone.
        \App\Models\SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $owner->id,
            'member_id'      => $member->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped-for-member',
        ]);

        $this->assertFalse(Gate::forUser($member)->allows('view', $account->fresh()));

        // And the cancelled invitation cannot be re-accepted.
        Passport::actingAs($member, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson("/api/v1/teams/invitations/{$invitation->token}/accept")
            ->assertStatus(404);
    }

    /**
     * B4: unsharing revokes ALL per-member rows for (team, account).
     */
    public function test_unshare_removes_all_member_rows() : void
    {
        $owner   = $this->createEncryptedUser();
        $memberA = $this->createEncryptedUser();
        $memberB = $this->createEncryptedUser();
        $team    = $this->makeTeamWithMember($owner, $memberA);
        $team->users()->attach($memberB->id, ['role' => 'member', 'joined_at' => now()]);

        $account = \App\Models\TwoFAccount::factory()->create([
            'user_id' => $owner->id,
        ]);

        foreach ([$memberA, $memberB] as $member) {
            \App\Models\SharedAccount::create([
                'team_id'        => $team->id,
                'twofaccount_id' => $account->id,
                'shared_by'      => $owner->id,
                'member_id'      => $member->id,
                'access_level'   => 'read',
                'wrapped_key'    => 'wrapped-for-' . $member->id,
            ]);
        }

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson("/api/v1/teams/{$team->id}/share/{$account->id}")
            ->assertStatus(200);

        $this->assertSame(0, \App\Models\SharedAccount::where('team_id', $team->id)
            ->where('twofaccount_id', $account->id)
            ->count());
    }

    /**
     * RT7/B7: a secret change revokes all shares and flags the revoked
     * members in the response; a metadata-only edit keeps the shares.
     */
    public function test_secret_change_revokes_shares_and_notifies_owner() : void
    {
        $owner  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();
        $team   = $this->makeTeamWithMember($owner, $member);

        $account = \App\Models\TwoFAccount::factory()->create([
            'user_id' => $owner->id,
            'account' => 'user@example.com',
            'service' => 'old-service',
            'secret'  => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567',
        ]);

        \App\Models\SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $account->id,
            'shared_by'      => $owner->id,
            'member_id'      => $member->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped-for-member',
        ]);

        // Metadata-only edit: shares must survive.
        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $this
            ->putJson("/api/v1/twofaccounts/{$account->id}", [
                'service'   => 'new-service',
                'account'   => $account->account,
                'icon'      => null,
                'otp_type'  => 'totp',
                'secret'    => $account->secret,
                'digits'    => 6,
                'algorithm' => 'sha1',
                'period'    => 30,
            ])
            ->assertStatus(200)
            ->assertJsonMissing(['shares_revoked' => true]);

        $this->assertSame(1, \App\Models\SharedAccount::where('twofaccount_id', $account->id)->count());

        // Secret change: shares must be revoked and members flagged.
        $this
            ->putJson("/api/v1/twofaccounts/{$account->id}", [
                'service'   => 'new-service',
                'account'   => $account->account,
                'icon'      => null,
                'otp_type'  => 'totp',
                'secret'    => 'QPFPJ7IOSZHDU3DLGFWA3Y2W64DQ',
                'digits'    => 6,
                'algorithm' => 'sha1',
                'period'    => 30,
            ])
            ->assertStatus(200)
            ->assertJsonFragment([
                'shares_revoked'  => true,
                'revoked_members' => [
                    ['id' => $member->id, 'name' => $member->name, 'email' => $member->email],
                ],
            ]);

        $this->assertSame(0, \App\Models\SharedAccount::where('twofaccount_id', $account->id)->count());
    }

    /**
     * B9: the shared-with-me virtual group (-4) lists accounts shared with
     * the requester (which are owned by someone else).
     */
    public function test_shared_with_me_group_lists_shared_accounts() : void
    {
        $owner  = $this->createEncryptedUser();
        $member = $this->createEncryptedUser();
        $team   = $this->makeTeamWithMember($owner, $member);

        $ownAccount    = \App\Models\TwoFAccount::factory()->create(['user_id' => $member->id]);
        $sharedAccount = \App\Models\TwoFAccount::factory()->create(['user_id' => $owner->id]);

        \App\Models\SharedAccount::create([
            'team_id'        => $team->id,
            'twofaccount_id' => $sharedAccount->id,
            'shared_by'      => $owner->id,
            'member_id'      => $member->id,
            'access_level'   => 'read',
            'wrapped_key'    => 'wrapped-for-member',
        ]);

        Passport::actingAs($member, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/twofaccounts?group_id=-4');

        $response->assertStatus(200);

        $ids = collect($response->json('data') ?? $response->json())->pluck('id');

        $this->assertContains($sharedAccount->id, $ids);
        $this->assertNotContains($ownAccount->id, $ids);
    }
}
