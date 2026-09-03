<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class VaultControllerTest extends TestCase
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

    public function test_user_can_list_vaults() : void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['name' => 'My Vault']);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/vaults');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['name' => 'My Vault']);
    }

    public function test_user_can_create_vault() : void
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/vaults', [
            'name' => 'New Vault',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Vault']);

        $this->assertDatabaseHas('vaults', [
            'user_id' => $user->id,
            'name'    => 'New Vault',
        ]);
    }

    public function test_returns_422_on_11th_vault() : void
    {
        $user = $this->createEncryptedUser();
        Vault::factory()->count(10)->for($user)->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/vaults', [
            'name' => 'Vault Eleven',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_rename_vault() : void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['name' => 'Old Name']);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->putJson("/api/v1/vaults/{$vault->id}", [
            'name' => 'Renamed Vault',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Renamed Vault']);

        $this->assertDatabaseHas('vaults', [
            'id'   => $vault->id,
            'name' => 'Renamed Vault',
        ]);
    }

    public function test_cannot_delete_default_vault() : void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['is_default' => true]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->deleteJson("/api/v1/vaults/{$vault->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('vaults', ['id' => $vault->id]);
    }

    public function test_can_delete_non_default_vault() : void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['is_default' => false]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->deleteJson("/api/v1/vaults/{$vault->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('vaults', ['id' => $vault->id]);
    }

    public function test_vault_ownership_enforced() : void
    {
        $owner     = $this->createEncryptedUser();
        $otherUser = $this->createEncryptedUser();
        $vault     = Vault::factory()->for($owner)->create();

        Passport::actingAs($otherUser, ['legacy_full_access'], 'api-guard');
        $response = $this->putJson("/api/v1/vaults/{$vault->id}", [
            'name' => 'Hijacked Name',
        ]);

        $response->assertStatus(404);
        $this->assertDatabaseHas('vaults', [
            'id'   => $vault->id,
            'name' => $vault->name,
        ]);
    }

    public function test_user_can_lock_vault() : void
    {
        $user  = $this->createEncryptedUser();
        $vault = Vault::factory()->for($user)->create(['is_locked' => false]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/vaults/{$vault->id}/lock");

        $response->assertStatus(200);
        $this->assertTrue($vault->fresh()->is_locked);
    }

    public function test_setup_encryption_refuses_when_already_configured() : void
    {
        $user = $this->createEncryptedUser();

        // B11: overwriting the salt would brick every secret encrypted under
        // the previous key.
        $vault = Vault::factory()->create([
            'user_id'              => $user->id,
            'encryption_salt'      => 'first-salt',
            'encryption_test_value' => 'first-test-value',
            'is_default'           => false,
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson("/api/v1/vaults/{$vault->id}/encryption", [
                'salt'       => 'second-salt',
                'test_value' => 'second-test-value',
            ])
            ->assertStatus(409);

        $this->assertDatabaseHas('vaults', [
            'id'              => $vault->id,
            'encryption_salt' => 'first-salt',
        ]);
    }

    public function test_user_can_unlock_locked_vault() : void
    {
        $user = $this->createEncryptedUser();

        // B11: the unlock service method previously had no route.
        $vault = Vault::factory()->create([
            'user_id'    => $user->id,
            'is_locked'  => true,
            'is_default' => false,
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson("/api/v1/vaults/{$vault->id}/unlock")
            ->assertStatus(200)
            ->assertJsonFragment(['message' => 'Vault unlocked']);

        $this->assertDatabaseHas('vaults', [
            'id'        => $vault->id,
            'is_locked' => false,
        ]);
    }
}
