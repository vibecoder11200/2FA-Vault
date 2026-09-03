<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TagController API endpoint tests.
 * Controller: App\Api\v1\Controllers\TagController
 */
class TagControllerTest extends TestCase
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

    #[Test]
    public function test_user_can_list_tags()
    {
        $user = $this->createEncryptedUser();
        Tag::factory()->for($user)->create(['name' => 'Alpha']);
        Tag::factory()->for($user)->create(['name' => 'Bravo']);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/tags');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Alpha'])
            ->assertJsonFragment(['name' => 'Bravo']);
    }

    #[Test]
    public function test_user_can_create_tag()
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/tags', [
            'name'  => 'Work',
            'color' => '#ff5500',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name'  => 'Work',
                'color' => '#ff5500',
            ]);

        $this->assertDatabaseHas('tags', [
            'name'    => 'Work',
            'color'   => '#ff5500',
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function test_returns_default_color_when_not_provided()
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/tags', [
            'name' => 'NoColor',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'name'  => 'NoColor',
                'color' => '#3273dc',
            ]);

        $this->assertDatabaseHas('tags', [
            'name'    => 'NoColor',
            'color'   => '#3273dc',
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function test_user_can_update_tag()
    {
        $user = $this->createEncryptedUser();
        $tag  = Tag::factory()->for($user)->create(['name' => 'Old', 'color' => '#111111']);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->putJson("/api/v1/tags/{$tag->id}", [
            'name'  => 'New',
            'color' => '#abcdef',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'name'  => 'New',
                'color' => '#abcdef',
            ]);

        $this->assertDatabaseHas('tags', [
            'id'    => $tag->id,
            'name'  => 'New',
            'color' => '#abcdef',
        ]);
    }

    #[Test]
    public function test_user_can_delete_tag()
    {
        $user = $this->createEncryptedUser();
        $tag  = Tag::factory()->for($user)->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->deleteJson("/api/v1/tags/{$tag->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('tags', ['id' => $tag->id]);
    }

    #[Test]
    public function test_tag_ownership_enforced()
    {
        $owner     = $this->createEncryptedUser();
        $otherUser = $this->createEncryptedUser();
        $tag       = Tag::factory()->for($owner)->create();

        // Other user cannot update
        Passport::actingAs($otherUser, ['legacy_full_access'], 'api-guard');
        $this
            ->putJson("/api/v1/tags/{$tag->id}", ['name' => 'Hacked'])
            ->assertStatus(403);

        // Other user cannot delete
        Passport::actingAs($otherUser, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson("/api/v1/tags/{$tag->id}")
            ->assertStatus(403);

        // Tag should remain unchanged
        $this->assertDatabaseHas('tags', ['id' => $tag->id, 'name' => $tag->name]);
    }

    #[Test]
    public function test_can_sync_tags_to_account()
    {
        $user    = $this->createEncryptedUser();
        $tag1    = Tag::factory()->for($user)->create();
        $tag2    = Tag::factory()->for($user)->create();
        $account = TwoFAccount::factory()->for($user)->create();

        // Sync both tags
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson("/api/v1/twofaccounts/{$account->id}/tags", [
                'tags' => [$tag1->id, $tag2->id],
            ]);

        $response->assertStatus(200);
        $this->assertCount(2, $account->fresh()->tags);

        // Sync to only one tag (replaces)
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson("/api/v1/twofaccounts/{$account->id}/tags", [
                'tags' => [$tag1->id],
            ]);

        $response->assertStatus(200);
        $this->assertCount(1, $account->fresh()->tags);
        $this->assertEquals($tag1->id, $account->fresh()->tags->first()->id);

        // Sync to empty (removes all)
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson("/api/v1/twofaccounts/{$account->id}/tags", [
                'tags' => [],
            ]);

        $response->assertStatus(200);
        $this->assertCount(0, $account->fresh()->tags);
    }

    #[Test]
    public function test_color_must_be_valid_hex()
    {
        $user = $this->createEncryptedUser();

        // Invalid hex format
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson('/api/v1/tags', [
                'name'  => 'BadColor',
                'color' => 'not-a-hex',
            ])
            ->assertStatus(422);

        // Partial hex (too short)
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson('/api/v1/tags', [
                'name'  => 'ShortHex',
                'color' => '#fff',
            ])
            ->assertStatus(422);

        // Missing hash prefix
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson('/api/v1/tags', [
                'name'  => 'NoHash',
                'color' => 'aabbcc',
            ])
            ->assertStatus(422);

        // Valid hex succeeds
        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson('/api/v1/tags', [
                'name'  => 'ValidColor',
                'color' => '#aabbcc',
            ])
            ->assertStatus(201);
    }
}
