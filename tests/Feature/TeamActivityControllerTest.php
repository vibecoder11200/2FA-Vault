<?php

namespace Tests\Feature;

use App\Enums\TeamAction;
use App\Models\Team;
use App\Models\TeamActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * TeamActivityController Tests
 *
 * Tests for the team activity log API endpoints.
 */
class TeamActivityControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $member;

    private User $outsider;

    private Team $team;

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

    protected function setUp() : void
    {
        parent::setUp();

        $this->owner    = $this->createEncryptedUser();
        $this->admin    = $this->createEncryptedUser();
        $this->member   = $this->createEncryptedUser();
        $this->outsider = $this->createEncryptedUser();

        $this->team = Team::factory()->create(['owner_id' => $this->owner->id]);
        $this->team->users()->attach($this->owner->id, ['role' => 'owner', 'joined_at' => now()]);
        $this->team->users()->attach($this->admin->id, ['role' => 'admin', 'joined_at' => now()]);
        $this->team->users()->attach($this->member->id, ['role' => 'member', 'joined_at' => now()]);
    }

    /**
     * Seed activity log entries for filter/pagination tests.
     */
    private function seedActivityLogs() : void
    {
        TeamActivityLog::create([
            'team_id'    => $this->team->id,
            'user_id'    => $this->owner->id,
            'action'     => TeamAction::TEAM_CREATED->value,
            'created_at' => now()->subDays(3),
        ]);
        TeamActivityLog::create([
            'team_id'    => $this->team->id,
            'user_id'    => $this->admin->id,
            'action'     => TeamAction::MEMBER_INVITED->value,
            'created_at' => now()->subDays(2),
        ]);
        TeamActivityLog::create([
            'team_id'    => $this->team->id,
            'user_id'    => $this->owner->id,
            'action'     => TeamAction::ACCOUNT_SHARED->value,
            'created_at' => now()->subDay(),
        ]);
    }

    /**
     * Test team owner can view activity log.
     */
    public function test_team_owner_can_view_activity_log() : void
    {
        $this->seedActivityLogs();

        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/teams/{$this->team->id}/activity");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [
                '*' => ['id', 'team_id', 'user_id', 'action', 'metadata', 'created_at'],
            ],
            'current_page',
            'total',
        ]);
    }

    /**
     * Test team admin can view activity log.
     */
    public function test_team_admin_can_view_activity_log() : void
    {
        $this->seedActivityLogs();

        Passport::actingAs($this->admin, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/teams/{$this->team->id}/activity");

        $response->assertStatus(200);
        $response->assertJsonPath('total', 3);
    }

    /**
     * Test team member (regular role) cannot view activity log.
     */
    public function test_team_member_cannot_view_activity_log() : void
    {
        $this->seedActivityLogs();

        Passport::actingAs($this->member, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/teams/{$this->team->id}/activity");

        $response->assertStatus(403);
    }

    /**
     * Test non-member cannot view activity log.
     */
    public function test_non_member_cannot_view_activity_log() : void
    {
        $this->seedActivityLogs();

        Passport::actingAs($this->outsider, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/teams/{$this->team->id}/activity");

        $response->assertStatus(403);
    }

    /**
     * Test activity log can be filtered by action.
     */
    public function test_activity_log_filters_by_action() : void
    {
        $this->seedActivityLogs();

        // Filter for member.invited only
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/teams/{$this->team->id}/activity?actions=member.invited");

        $response->assertStatus(200);
        $response->assertJsonPath('total', 1);
        $response->assertJsonPath('data.0.action', 'member.invited');

        // Filter for multiple actions
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/teams/{$this->team->id}/activity?actions=team.created,account.shared");

        $response->assertStatus(200);
        $response->assertJsonPath('total', 2);
    }

    /**
     * Test export requires owner role (admin gets 403).
     *
     * CSP middleware calls withHeaders() which is incompatible with
     * StreamedResponse — disable it for the export endpoint test.
     */
    public function test_export_requires_owner_role() : void
    {
        $this->seedActivityLogs();

        // Admin cannot export (no stream involved — 403 returned before streamDownload)
        Passport::actingAs($this->admin, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/teams/{$this->team->id}/activity/export");

        $response->assertStatus(403);

        // Owner can export — bypass CSP middleware for StreamedResponse compatibility
        Passport::actingAs($this->owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->withoutMiddleware(\App\Http\Middleware\AddContentSecurityPolicyHeaders::class)
            ->getJson("/api/v1/teams/{$this->team->id}/activity/export");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/json');
    }
}
