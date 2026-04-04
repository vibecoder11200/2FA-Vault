<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TeamInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test user can create a team
     */
    public function test_user_can_create_team()
    {
        $user = User::factory()->create();
        
        $response = $this->actingAs($user)->postJson('/api/teams', [
            'name' => 'Development Team',
            'description' => 'Team for developers'
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'name',
                    'description',
                    'owner_id',
                    'created_at'
                ]
            ]);

        $this->assertDatabaseHas('teams', [
            'name' => 'Development Team',
            'owner_id' => $user->id
        ]);

        // Check owner is automatically added as member
        $this->assertDatabaseHas('team_members', [
            'team_id' => $response->json('data.id'),
            'user_id' => $user->id,
            'role' => 'owner'
        ]);
    }

    /**
     * Test user can list teams
     */
    public function test_user_can_list_teams()
    {
        $user = User::factory()->create();
        
        // Create teams where user is member
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();
        $team3 = Team::factory()->create(); // User is not member
        
        TeamMember::create([
            'team_id' => $team1->id,
            'user_id' => $user->id,
            'role' => 'owner'
        ]);
        
        TeamMember::create([
            'team_id' => $team2->id,
            'user_id' => $user->id,
            'role' => 'member'
        ]);

        $response = $this->actingAs($user)->getJson('/api/teams');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['id' => $team1->id])
            ->assertJsonFragment(['id' => $team2->id])
            ->assertJsonMissing(['id' => $team3->id]);
    }

    /**
     * Test user can invite to team
     */
    public function test_user_can_invite_to_team()
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner'
        ]);

        $response = $this->actingAs($owner)->postJson("/api/teams/{$team->id}/invitations", [
            'email' => $invitee->email,
            'role' => 'member'
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('team_invitations', [
            'team_id' => $team->id,
            'email' => $invitee->email,
            'role' => 'member',
            'status' => 'pending'
        ]);
    }

    /**
     * Test user can join team via invite
     */
    public function test_user_can_join_team_via_invite()
    {
        $owner = User::factory()->create();
        $invitee = User::factory()->create();
        
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        
        $invitation = TeamInvitation::create([
            'team_id' => $team->id,
            'email' => $invitee->email,
            'role' => 'member',
            'token' => \Str::random(32),
            'status' => 'pending'
        ]);

        $response = $this->actingAs($invitee)->postJson("/api/teams/invitations/{$invitation->token}/accept");

        $response->assertStatus(200);

        $this->assertDatabaseHas('team_members', [
            'team_id' => $team->id,
            'user_id' => $invitee->id,
            'role' => 'member'
        ]);

        $this->assertDatabaseHas('team_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted'
        ]);
    }

    /**
     * Test user can leave team
     */
    public function test_user_can_leave_team()
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner'
        ]);
        
        $membership = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'member'
        ]);

        $response = $this->actingAs($member)->deleteJson("/api/teams/{$team->id}/members/{$member->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('team_members', [
            'id' => $membership->id
        ]);
    }

    /**
     * Test owner can delete team
     */
    public function test_owner_can_delete_team()
    {
        $owner = User::factory()->create();
        
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner'
        ]);

        $response = $this->actingAs($owner)->deleteJson("/api/teams/{$team->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('teams', [
            'id' => $team->id
        ]);
    }

    /**
     * Test admin can remove member
     */
    public function test_admin_can_remove_member()
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();
        
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner'
        ]);
        
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $admin->id,
            'role' => 'admin'
        ]);
        
        $membershipToRemove = TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'member'
        ]);

        $response = $this->actingAs($admin)->deleteJson("/api/teams/{$team->id}/members/{$member->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('team_members', [
            'id' => $membershipToRemove->id
        ]);
    }

    /**
     * Test viewer cannot update team
     */
    public function test_viewer_cannot_update_team()
    {
        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner'
        ]);
        
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $viewer->id,
            'role' => 'viewer'
        ]);

        $response = $this->actingAs($viewer)->putJson("/api/teams/{$team->id}", [
            'name' => 'Updated Team Name'
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseHas('teams', [
            'id' => $team->id,
            'name' => $team->name // Name unchanged
        ]);
    }

    /**
     * Test unauthorized user cannot access team
     */
    public function test_unauthorized_user_cannot_access_team()
    {
        $owner = User::factory()->create();
        $unauthorized = User::factory()->create();
        
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        TeamMember::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'role' => 'owner'
        ]);

        $response = $this->actingAs($unauthorized)->getJson("/api/teams/{$team->id}");

        $response->assertStatus(403);
    }
}
