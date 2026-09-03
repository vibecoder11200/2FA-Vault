<?php

namespace Tests\Api\v1;

use App\Facades\Settings;
use App\Models\SecureNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SecureNoteTest test class
 */
#[CoversClass(\App\Api\v1\Controllers\SecureNoteController::class)]
class SecureNoteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_user_can_create_a_secure_note()
    {
        $user = User::factory()->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/secure-notes', [
            'title'        => 'My secret',
            'content'      => 'top secret content',
            'content_type' => 'plain',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['title' => 'My secret']);

        $this->assertDatabaseHas('secure_notes', ['user_id' => $user->id]);
    }

    #[Test]
    public function test_stored_content_differs_from_plaintext_when_encryption_enabled()
    {
        // Enable encryption and clear any cached settings before the request.
        Settings::set('useEncryption', true);

        $user      = User::factory()->create();
        $plaintext = 'top secret content that must not appear in the DB';

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this->postJson('/api/v1/secure-notes', [
            'title'   => 'Encrypted note',
            'content' => $plaintext,
        ])->assertStatus(201);

        $rawContent = DB::table('secure_notes')->where('user_id', $user->id)->value('content');

        $this->assertNotNull($rawContent);
        $this->assertNotSame($plaintext, $rawContent);
        $this->assertStringNotContainsString($plaintext, $rawContent);
    }

    #[Test]
    public function test_user_can_list_and_read_own_notes()
    {
        $user = User::factory()->create();
        $note = SecureNote::factory()->forUser($user)->create(['title' => 'Visible']);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson('/api/v1/secure-notes')
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'Visible']);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson('/api/v1/secure-notes/' . $note->id)
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'Visible']);
    }

    #[Test]
    public function test_user_cannot_read_other_users_note()
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $note     = SecureNote::factory()->forUser($owner)->create();

        Passport::actingAs($intruder, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson('/api/v1/secure-notes/' . $note->id)
            ->assertForbidden();
    }

    #[Test]
    public function test_user_can_update_own_note()
    {
        $user = User::factory()->create();
        $note = SecureNote::factory()->forUser($user)->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->putJson('/api/v1/secure-notes/' . $note->id, [
                'title'   => 'Updated',
                'content' => 'new content',
            ])
            ->assertStatus(200)
            ->assertJsonFragment(['title' => 'Updated']);
    }

    #[Test]
    public function test_user_can_delete_own_note()
    {
        $user = User::factory()->create();
        $note = SecureNote::factory()->forUser($user)->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson('/api/v1/secure-notes/' . $note->id)
            ->assertStatus(200);

        $this->assertDatabaseMissing('secure_notes', ['id' => $note->id]);
    }

    #[Test]
    public function test_unauthenticated_request_returns_401()
    {
        $this->getJson('/api/v1/secure-notes')->assertUnauthorized();
        $this->postJson('/api/v1/secure-notes', ['title' => 'x', 'content' => 'y'])->assertUnauthorized();
    }
}
