<?php

namespace Tests\Feature\Services;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\TeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TeamService Tests
 *
 * Tests for the team management service layer.
 */
class TeamServiceTest extends TestCase
{
    use RefreshDatabase;

    private TeamService $teamService;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamService = new TeamService();
        $this->owner = User::factory()->create();
    }

    /**
     * Test creating a team
     */
    public function test_can_create_team(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Development Team');

        $this->assertInstanceOf(Team::class, $team);
        $this->assertEquals('Development Team', $team->name);
        $this->assertEquals($this->owner->id, $team->owner_id);

        // Owner should be added as member
        $this->assertTrue($team->hasMember($this->owner->id));
        $this->assertEquals('owner', $team->getUserRole($this->owner->id));
    }

    /**
     * Test cannot create team beyond limit
     */
    public function test_cannot_create_team_beyond_limit(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('reached the maximum number of teams');

        // Create max teams
        for ($i = 0; $i < 10; $i++) {
            $this->teamService->createTeam($this->owner, "Team {$i}");
        }

        // Try to create one more
        $this->teamService->createTeam($this->owner, 'Extra Team');
    }

    /**
     * Test updating team name
     */
    public function test_can_update_team_name(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Original Name');

        $updated = $this->teamService->updateTeam($team, $this->owner, 'New Name');

        $this->assertEquals('New Name', $updated->name);
    }

    /**
     * Test non-owner admin can update team
     */
    public function test_admin_can_update_team(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team Name');
        $admin = User::factory()->create();
        $team->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $updated = $this->teamService->updateTeam($team, $admin, 'Updated by Admin');

        $this->assertEquals('Updated by Admin', $updated->name);
    }

    /**
     * Test member cannot update team
     */
    public function test_member_cannot_update_team(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('do not have permission');

        $team = $this->teamService->createTeam($this->owner, 'Team Name');
        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $this->teamService->updateTeam($team, $member, 'Hacked Name');
    }

    /**
     * Test deleting a team
     */
    public function test_owner_can_delete_team(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'To Delete');

        $result = $this->teamService->deleteTeam($team, $this->owner);

        $this->assertTrue($result);
        $this->assertSoftDeleted('teams', ['id' => $team->id]);
    }

    /**
     * Test non-owner cannot delete team
     */
    public function test_non_owner_cannot_delete_team(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only the team owner');

        $team = $this->teamService->createTeam($this->owner, 'Team');
        $other = User::factory()->create();

        $this->teamService->deleteTeam($team, $other);
    }

    /**
     * Test inviting a user to team
     */
    public function test_can_invite_user_to_team(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $invitation = $this->teamService->inviteUser($team, $this->owner, $invitee->email, 'admin');

        $this->assertInstanceOf(TeamInvitation::class, $invitation);
        $this->assertEquals($team->id, $invitation->team_id);
        $this->assertEquals($invitee->email, $invitation->email);
        $this->assertEquals('admin', $invitation->role);
        $this->assertEquals('pending', $invitation->status);
    }

    /**
     * Test member cannot invite users
     */
    public function test_member_cannot_invite_users(): void
    {
        $this->expectException(\Exception::class);

        $team = $this->teamService->createTeam($this->owner, 'Team');
        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $this->teamService->inviteUser($team, $member, 'someone@example.com');
    }

    /**
     * Test accepting team invitation
     */
    public function test_can_accept_invitation(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $invitation = $this->teamService->inviteUser($team, $this->owner, $invitee->email, 'member');

        $result = $this->teamService->acceptInvitation($invitation, $invitee);

        $this->assertInstanceOf(Team::class, $result);
        $this->assertTrue($result->hasMember($invitee->id));
        $this->assertEquals('member', $result->getUserRole($invitee->id));

        $invitation->refresh();
        $this->assertEquals('accepted', $invitation->status);
    }

    /**
     * Test cannot accept invitation for different email
     */
    public function test_cannot_accept_invitation_for_different_email(): void
    {
        $this->expectException(\Exception::class);

        $team = $this->teamService->createTeam($this->owner, 'Team');
        $invitee = User::factory()->create(['email' => 'invitee@example.com']);

        $invitation = $this->teamService->inviteUser($team, $this->owner, $invitee->email);

        $differentUser = User::factory()->create(['email' => 'different@example.com']);
        $this->teamService->acceptInvitation($invitation, $differentUser);
    }

    /**
     * Test joining team via invite code
     */
    public function test_can_join_team_via_invite_code(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $inviteCode = $team->generateInviteCode();

        $newMember = User::factory()->create();

        $result = $this->teamService->joinByInviteCode($inviteCode, $newMember);

        $this->assertInstanceOf(Team::class, $result);
        $this->assertTrue($result->hasMember($newMember->id));
        $this->assertEquals('member', $result->getUserRole($newMember->id));
    }

    /**
     * Test cannot join if already member
     */
    public function test_cannot_join_if_already_member(): void
    {
        $this->expectException(\Exception::class);

        $team = $this->teamService->createTeam($this->owner, 'Team');
        $inviteCode = $team->generateInviteCode();

        $this->teamService->joinByInviteCode($inviteCode, $this->owner);
    }

    /**
     * Test leaving a team
     */
    public function test_can_leave_team(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $result = $this->teamService->leaveTeam($team, $member);

        $this->assertTrue($result);
        $this->assertFalse($team->hasMember($member->id));
    }

    /**
     * Test owner cannot leave team
     */
    public function test_owner_cannot_leave_team(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('owner cannot leave');

        $team = $this->teamService->createTeam($this->owner, 'Team');

        $this->teamService->leaveTeam($team, $this->owner);
    }

    /**
     * Test removing team member
     */
    public function test_can_remove_member(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $result = $this->teamService->removeMember($team, $this->owner, $member->id);

        $this->assertTrue($result);
        $this->assertFalse($team->hasMember($member->id));
    }

    /**
     * Test admin can remove member
     */
    public function test_admin_can_remove_member(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $admin = User::factory()->create();
        $team->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $result = $this->teamService->removeMember($team, $admin, $member->id);

        $this->assertTrue($result);
    }

    /**
     * Test cannot remove team owner
     */
    public function test_cannot_remove_team_owner(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot remove team owner');

        $team = $this->teamService->createTeam($this->owner, 'Team');
        $admin = User::factory()->create();
        $team->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $this->teamService->removeMember($team, $admin, $this->owner->id);
    }

    /**
     * Test updating member role
     */
    public function test_can_update_member_role(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $result = $this->teamService->updateMemberRole($team, $this->owner, $member->id, 'admin');

        $this->assertTrue($result);
        $team->refresh();
        $this->assertEquals('admin', $team->getUserRole($member->id));
    }

    /**
     * Test only owner can update roles
     */
    public function test_only_owner_can_update_roles(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Only the team owner');

        $team = $this->teamService->createTeam($this->owner, 'Team');
        $admin = User::factory()->create();
        $team->users()->attach($admin->id, ['role' => 'admin', 'joined_at' => now()]);

        $member = User::factory()->create();
        $team->users()->attach($member->id, ['role' => 'member', 'joined_at' => now()]);

        $this->teamService->updateMemberRole($team, $admin, $member->id, 'viewer');
    }

    /**
     * Test sharing account with team
     */
    public function test_can_share_account_with_team(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $account = \App\Models\TwoFAccount::factory()->create([
            'user_id' => $this->owner->id,
        ]);

        $shared = $this->teamService->shareAccountWithTeam($account, $team, $this->owner, 'view');

        $this->assertInstanceOf(\App\Models\SharedAccount::class, $shared);
        $this->assertEquals($team->id, $shared->team_id);
        $this->assertEquals($account->id, $shared->twofaccount_id);
        $this->assertEquals('view', $shared->access_level);
    }

    /**
     * Test cannot share account you don't own
     */
    public function test_cannot_share_account_you_dont_own(): void
    {
        $this->expectException(\Exception::class);

        $otherUser = User::factory()->create();
        $team = $this->teamService->createTeam($this->owner, 'Team');
        $team->users()->attach($otherUser->id, ['role' => 'admin', 'joined_at' => now()]);

        $account = \App\Models\TwoFAccount::factory()->create([
            'user_id' => $this->owner->id,
        ]);

        $this->teamService->shareAccountWithTeam($account, $team, $otherUser, 'view');
    }

    /**
     * Test getting team stats
     */
    public function test_get_team_stats(): void
    {
        $team = $this->teamService->createTeam($this->owner, 'Team');

        // Add members
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();
        $team->users()->attach($member1->id, ['role' => 'member', 'joined_at' => now()]);
        $team->users()->attach($member2->id, ['role' => 'member', 'joined_at' => now()]);

        // Create invitation
        $invitee = User::factory()->create();
        $this->teamService->inviteUser($team, $this->owner, $invitee->email);

        // Share account
        $account = \App\Models\TwoFAccount::factory()->create(['user_id' => $this->owner->id]);
        $this->teamService->shareAccountWithTeam($account, $team, $this->owner);

        $stats = $this->teamService->getTeamStats($team);

        $this->assertEquals(3, $stats['members_count']); // owner + 2 members
        $this->assertEquals(1, $stats['shared_accounts_count']);
        $this->assertEquals(1, $stats['invitations_pending']);
    }
}
