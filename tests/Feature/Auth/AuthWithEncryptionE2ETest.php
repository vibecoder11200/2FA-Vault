<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * Auth with Encryption E2E Tests
 *
 * Tests the complete workflow of authentication combined with E2EE:
 * - User login → encryption setup → vault unlock → use encrypted accounts
 * - Session lifecycle + encryption key lifecycle
 * - Vault state transitions (locked → unlocked → locked)
 */
class AuthWithEncryptionE2ETest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup Passport personal access client for API token creation
        $this->artisan('passport:client', ['--personal' => true, '--name' => 'Test Personal Access Client']);

        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
            'encryption_salt' => null,
            'encryption_test_value' => null,
            'encryption_version' => 0,
            'vault_locked' => false,
        ]);
    }

    /**
     * Test complete workflow: Login → Setup Encryption → Use App
     */
    public function test_complete_login_setup_encryption_workflow(): void
    {
        // 1. User logs in
        $token = $this->user->createToken('test-token')->accessToken;
        $this->assertNotNull($token);

        // 2. Check encryption status - should be disabled
        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/status');

        $response->assertOk()
            ->assertJson([
                'encryption_enabled' => false,
                'encryption_version' => 0,
                'vault_locked' => false,
            ]);

        // 3. Setup encryption
        $response = $this->withToken($token)
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => base64_encode(random_bytes(32)),
                'encryption_test_value' => json_encode([
                    'ciphertext' => base64_encode(random_bytes(32)),
                    'iv' => base64_encode(random_bytes(12)),
                    'authTag' => base64_encode(random_bytes(16)),
                ]),
                'encryption_version' => 1
            ]);

        $response->assertOk()
            ->assertJson([
                'encryption_enabled' => true
            ]);

        // 4. Verify encryption is now enabled
        $this->user->refresh();
        $this->assertEquals(1, $this->user->encryption_version);
        $this->assertFalse($this->user->vault_locked);

        // 5. Check encryption info
        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/info');

        $response->assertOk()
            ->assertJson([
                'encryption_enabled' => true,
                'encryption_version' => 1,
                'vault_locked' => false,
            ])
            ->assertJsonStructure([
                'encryption_enabled',
                'encryption_salt',
                'encryption_test_value',
                'encryption_version',
                'vault_locked',
            ]);
    }

    /**
     * Test vault state transitions: unlocked → locked → unlocked
     */
    public function test_vault_state_transitions(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        // Setup encryption
        $this->withToken($token)
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'test_salt',
                'encryption_test_value' => '{"test":"value"}',
                'encryption_version' => 1
            ]);

        // Initial state: unlocked
        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/info');

        $this->assertFalse($response->json('vault_locked'));

        // Lock the vault
        $response = $this->withToken($token)
            ->postJson('/api/v1/encryption/lock');

        $response->assertOk()
            ->assertJson(['vault_locked' => true]);

        // Verify vault is locked
        $this->user->refresh();
        $this->assertTrue($this->user->vault_locked);

        // Unlock via verification (client-side password check succeeded)
        $response = $this->withToken($token)
            ->postJson('/api/v1/encryption/verify', [
                'verification_result' => true
            ]);

        $response->assertOk()
            ->assertJson([
                'message' => 'Vault unlocked successfully',
                'vault_locked' => false
            ]);

        // Verify vault is unlocked
        $this->user->refresh();
        $this->assertFalse($this->user->vault_locked);
    }

    /**
     * Test failed verification keeps vault locked
     */
    public function test_failed_verification_keeps_vault_locked(): void
    {
        // Setup encryption with vault locked
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();

        // Failed verification (wrong password)
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/verify', [
                'verification_result' => false
            ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Verification failed',
                'vault_locked' => true
            ]);

        // Verify vault is still locked
        $this->user->refresh();
        $this->assertTrue($this->user->vault_locked);
    }

    /**
     * Test that operations require encryption to be enabled
     */
    public function test_vault_operations_require_encryption_enabled(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        // Try to lock without encryption enabled
        $response = $this->withToken($token)
            ->postJson('/api/v1/encryption/lock');

        $response->assertStatus(400)
            ->assertJson(['message' => 'Encryption is not enabled']);

        // Try to verify without encryption enabled
        $response = $this->withToken($token)
            ->postJson('/api/v1/encryption/verify', [
                'verification_result' => true
            ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Encryption is not enabled for this account']);
    }

    /**
     * Test getting encryption salt for key derivation
     */
    public function test_get_encryption_salt_for_key_derivation(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        // Setup encryption
        $salt = base64_encode(random_bytes(32));
        $this->user->encryption_salt = $salt;
        $this->user->encryption_version = 1;
        $this->user->save();

        // Get salt
        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/salt');

        $response->assertOk()
            ->assertJson([
                'encryption_salt' => $salt
            ]);
    }

    /**
     * Test getting salt fails when encryption not enabled
     */
    public function test_get_salt_fails_when_encryption_not_enabled(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/salt');

        $response->assertStatus(400)
            ->assertJson(['message' => 'Encryption is not enabled']);
    }

    /**
     * Test vault state persists across requests
     */
    public function test_vault_state_persists_across_requests(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        // Setup encryption
        $this->withToken($token)
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'test_salt',
                'encryption_test_value' => '{"test":"value"}',
                'encryption_version' => 1
            ]);

        // Lock vault
        $this->withToken($token)
            ->postJson('/api/v1/encryption/lock');

        // Check state in new request
        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/info');

        $this->assertTrue($response->json('vault_locked'));

        // Check state again in another request
        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/status');

        $this->assertTrue($response->json('vault_locked'));
    }

    /**
     * Test disabling encryption requires password confirmation
     */
    public function test_disabling_encryption_requires_password(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        // Setup encryption
        $this->withToken($token)
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'test_salt',
                'encryption_test_value' => '{"test":"value"}',
                'encryption_version' => 1
            ]);

        // Try to disable without password
        $response = $this->withToken($token)
            ->deleteJson('/api/v1/encryption/disable', [
                'confirm' => true
            ]);

        $response->assertStatus(422);

        // Try to disable with wrong password
        $response = $this->withToken($token)
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'wrong_password',
                'confirm' => true
            ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid password']);

        // Verify encryption is still enabled
        $this->user->refresh();
        $this->assertEquals(1, $this->user->encryption_version);

        // Disable with correct password
        $response = $this->withToken($token)
            ->deleteJson('/api/v1/encryption/disable', [
                'password' => 'password123',
                'confirm' => true
            ]);

        $response->assertOk()
            ->assertJson(['encryption_enabled' => false]);

        // Verify encryption is disabled
        $this->user->refresh();
        $this->assertEquals(0, $this->user->encryption_version);
        $this->assertNull($this->user->encryption_salt);
    }

    /**
     * Test encryption status includes backup information
     */
    public function test_encryption_status_includes_backup_info(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        // Setup encryption
        $this->withToken($token)
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'test_salt',
                'encryption_test_value' => '{"test":"value"}',
                'encryption_version' => 1
            ]);

        // Check status before backup
        $response = $this->withToken($token)
            ->getJson('/api/v1/encryption/status');

        $response->assertJson([
            'encryption_enabled' => true,
            'has_backup' => false,
        ]);

        // Simulate backup - update last_backup_at via raw SQL for SQLite compatibility
        $now = now()->format('Y-m-d H:i:s');
        \DB::statement("UPDATE users SET last_backup_at = ? WHERE id = ?", [$now, $this->user->id]);

        // Verify status after backup via service (bypasses Passport user caching)
        $freshUser = \App\Models\User::find($this->user->id);
        $this->assertNotNull($freshUser->last_backup_at, 'last_backup_at should be set in DB');

        $encryptionService = app(\App\Services\EncryptionService::class);
        $status = $encryptionService->getEncryptionStatus($freshUser);

        $this->assertTrue($status['encryption_enabled']);
        $this->assertTrue($status['has_backup']);
        $this->assertNotNull($status['last_backup_at']);
    }

    /**
     * Test encryption setup cannot be done twice
     */
    public function test_cannot_setup_encryption_twice(): void
    {
        $token = $this->user->createToken('test-token')->accessToken;

        // First setup
        $response = $this->withToken($token)
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'salt1',
                'encryption_test_value' => '{"test":"value1"}',
                'encryption_version' => 1
            ]);

        $response->assertOk();

        // Second setup should fail
        $response = $this->withToken($token)
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'salt2',
                'encryption_test_value' => '{"test":"value2"}',
                'encryption_version' => 1
            ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Encryption is already enabled for this account']);

        // Verify original values are kept
        $this->user->refresh();
        $this->assertEquals('salt1', $this->user->encryption_salt);
    }

    /**
     * Test all encryption endpoints require authentication
     */
    public function test_all_encryption_endpoints_require_authentication(): void
    {
        $endpoints = [
            ['GET', '/api/v1/encryption/info'],
            ['GET', '/api/v1/encryption/salt'],
            ['GET', '/api/v1/encryption/status'],
            ['POST', '/api/v1/encryption/setup'],
            ['POST', '/api/v1/encryption/verify'],
            ['POST', '/api/v1/encryption/lock'],
            ['DELETE', '/api/v1/encryption/disable'],
        ];

        foreach ($endpoints as [$method, $url]) {
            $response = $this->json($method, $url);
            $response->assertUnauthorized();
        }
    }
}
