<?php

namespace Tests\Api\v1;

use App\Models\User;
use App\Models\UserBackupDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BackupDestinationTest test class
 */
#[CoversClass(\App\Api\v1\Controllers\UserBackupDestinationController::class)]
class BackupDestinationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_unauthenticated_request_returns_401()
    {
        $this->getJson('/api/v1/user/backup-destinations')->assertUnauthorized();
        $this->postJson('/api/v1/user/backup-destinations', [])->assertUnauthorized();
    }

    #[Test]
    public function test_user_can_create_destination_and_config_persists_as_array()
    {
        Storage::fake('local');
        $user = User::factory()->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/user/backup-destinations', [
            'label'     => 'Local vault',
            'type'      => 'local',
            'is_active' => true,
            'config'    => ['path' => 'backups', 'secret_key' => 'SUPER_SECRET'],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['label' => 'Local vault', 'type' => 'local'])
            // masked summary only — raw credentials never exposed
            ->assertJsonMissing(['secret_key' => 'SUPER_SECRET']);

        // The stored config round-trips back to an array
        $destination = UserBackupDestination::first();
        $this->assertIsArray($destination->config);
        $this->assertSame('backups', $destination->config['path']);
    }

    #[Test]
    public function test_user_can_list_their_destinations_without_secrets()
    {
        $user = User::factory()->create();
        UserBackupDestination::create([
            'user_id'   => $user->id,
            'label'     => 'My backup',
            'type'      => 'local',
            'config'    => ['path' => 'backups', 'secret_key' => 'LEAK_ME_IF_BUG'],
            'is_active' => true,
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/user/backup-destinations');

        $response->assertStatus(200)->assertJsonMissing(['secret_key' => 'LEAK_ME_IF_BUG']);
    }

    #[Test]
    public function test_user_cannot_list_other_users_destinations()
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        UserBackupDestination::create([
            'user_id' => $owner->id, 'label' => 'Owner backup', 'type' => 'local',
            'config'  => ['path' => 'b'], 'is_active' => true,
        ]);

        Passport::actingAs($intruder, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson('/api/v1/user/backup-destinations')
            ->assertStatus(200)
            ->assertJsonMissing(['label' => 'Owner backup']);
    }

    #[Test]
    public function test_user_can_update_own_destination()
    {
        Storage::fake('local');
        $user        = User::factory()->create();
        $destination = UserBackupDestination::create([
            'user_id' => $user->id, 'label' => 'Old', 'type' => 'local',
            'config'  => ['path' => 'old'], 'is_active' => true,
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->putJson('/api/v1/user/backup-destinations/' . $destination->id, [
                'label'  => 'New',
                'type'   => 'local',
                'config' => ['path' => 'new'],
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['label' => 'New']);

        $this->assertSame('new', $destination->fresh()->config['path']);
    }

    #[Test]
    public function test_user_can_delete_own_destination()
    {
        $user        = User::factory()->create();
        $destination = UserBackupDestination::create([
            'user_id' => $user->id, 'label' => 'Doomed', 'type' => 'local',
            'config'  => ['path' => 'b'], 'is_active' => true,
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson('/api/v1/user/backup-destinations/' . $destination->id)
            ->assertStatus(204);

        $this->assertDatabaseMissing('user_backup_destinations', ['id' => $destination->id]);
    }

    #[Test]
    public function test_user_cannot_delete_other_users_destination()
    {
        $owner       = User::factory()->create();
        $intruder    = User::factory()->create();
        $destination = UserBackupDestination::create([
            'user_id' => $owner->id, 'label' => 'X', 'type' => 'local',
            'config'  => ['path' => 'b'], 'is_active' => true,
        ]);

        Passport::actingAs($intruder, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson('/api/v1/user/backup-destinations/' . $destination->id)
            ->assertNotFound();
    }

    #[Test]
    public function test_test_connection_succeeds_for_valid_local_destination()
    {
        Storage::fake('local');
        $user        = User::factory()->create();
        $destination = UserBackupDestination::create([
            'user_id' => $user->id, 'label' => 'Local', 'type' => 'local',
            'config'  => ['path' => 'backups'], 'is_active' => true,
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson('/api/v1/user/backup-destinations/' . $destination->id . '/test')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    #[Test]
    public function test_test_connection_endpoint_is_protected()
    {
        $this->postJson('/api/v1/user/backup-destinations/1/test')->assertUnauthorized();
    }
}
