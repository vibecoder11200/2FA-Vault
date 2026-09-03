<?php

namespace Tests\Feature\Http\Controllers;

use App\Http\Controllers\EncryptionController;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\EncryptionService;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * EncryptionControllerDisableTest test class
 *
 * Tests the encryption disable endpoint safety feature.
 */
#[CoversClass(EncryptionController::class)]
#[CoversClass(EncryptionService::class)]
class EncryptionControllerDisableTest extends FeatureTestCase
{
    private User $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'test_salt_base64',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version'    => 1,
            'vault_locked'          => false,
        ]);
    }

    #[Test]
    public function test_disable_returns_422_when_encrypted_accounts_exist() : void
    {
        TwoFAccount::factory()->count(2)->encrypted()->create([
            'user_id' => $this->user->id,
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'password',
                'confirm'  => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'encrypted_count']);
    }

    #[Test]
    public function test_disable_response_includes_encrypted_count() : void
    {
        TwoFAccount::factory()->count(3)->encrypted()->create([
            'user_id' => $this->user->id,
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'password',
                'confirm'  => true,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'encrypted_count' => 3,
            ]);
    }

    #[Test]
    public function test_disable_returns_422_when_encrypted_secure_notes_exist() : void
    {
        // B10: an E2EE-encrypted note (client-side JSON crypto envelope) must
        // block disabling E2EE, otherwise the note is permanently unreadable.
        \App\Models\SecureNote::factory()->forUser($this->user)->create([
            'title'   => json_encode(['ciphertext' => 'enc', 'iv' => 'iv', 'authTag' => 'tag']),
            'content' => json_encode(['ciphertext' => 'enc', 'iv' => 'iv', 'authTag' => 'tag']),
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'password',
                'confirm'  => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'encrypted_count']);
    }

    #[Test]
    public function test_disable_succeeds_when_no_encrypted_accounts() : void
    {
        // No encrypted TwoFAccounts created

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'password',
                'confirm'  => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'encryption_enabled' => false,
            ]);

        $this->user->refresh();
        $this->assertFalse($this->user->encryption_enabled);
        $this->assertNull($this->user->encryption_salt);
        $this->assertEquals(0, $this->user->encryption_version);
    }

    #[Test]
    public function test_disable_returns_401_with_wrong_password() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'wrong-password',
                'confirm'  => true,
            ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid password',
            ]);

        // Encryption should still be enabled
        $this->user->refresh();
        $this->assertTrue($this->user->encryption_enabled);
    }

    #[Test]
    public function test_disable_requires_password() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'confirm' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    #[Test]
    public function test_disable_requires_confirm_accepted() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'password',
                'confirm'  => false,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['confirm']);
    }

    #[Test]
    public function test_disable_requires_authentication() : void
    {
        $response = $this->deleteJson('/api/v1/encryption/disable', [
            'password' => 'password',
            'confirm'  => true,
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function test_disable_does_not_affect_other_users_encrypted_accounts() : void
    {
        // Our user has encrypted accounts
        TwoFAccount::factory()->count(2)->encrypted()->create([
            'user_id' => $this->user->id,
        ]);

        // Another user also has encrypted accounts
        $otherUser = User::factory()->withE2EE()->create();
        TwoFAccount::factory()->count(3)->encrypted()->create([
            'user_id' => $otherUser->id,
        ]);

        // Our user tries to disable — blocked by their own accounts
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'password',
                'confirm'  => true,
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'encrypted_count' => 2,
            ]);

        // Other user's encryption should be untouched
        $otherUser->refresh();
        $this->assertTrue($otherUser->encryption_enabled);
    }
}
