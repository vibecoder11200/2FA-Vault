<?php

namespace Tests\Feature\Services;

use App\Models\User;
use App\Models\TwoFAccount;
use App\Services\EncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EncryptionService Unit Tests
 *
 * Tests for the server-side encryption service layer.
 * Remember: The server NEVER decrypts secrets, it only validates structure
 * and stores encrypted payloads from the client.
 */
class EncryptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private EncryptionService $encryptionService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encryptionService = new EncryptionService();
        $this->user = User::factory()->create();
    }

    /**
     * Test that encryption is disabled for new users by default
     */
    public function test_encryption_disabled_by_default_for_new_users(): void
    {
        $isEnabled = $this->encryptionService->isEncryptionEnabled($this->user);

        $this->assertFalse($isEnabled);
        $this->assertEquals(0, $this->user->encryption_version);
    }

    /**
     * Test encryption setup
     */
    public function test_can_setup_encryption_for_user(): void
    {
        $salt = base64_encode(random_bytes(32));
        $testValue = json_encode([
            'ciphertext' => base64_encode(random_bytes(32)),
            'iv' => base64_encode(random_bytes(12)),
            'authTag' => base64_encode(random_bytes(16)),
        ]);

        $result = $this->encryptionService->setupEncryption(
            $this->user,
            $salt,
            $testValue,
            1
        );

        $this->assertTrue($result);
        $this->user->refresh();

        $this->assertEquals(1, $this->user->encryption_version);
        $this->assertEquals($salt, $this->user->encryption_salt);
        $this->assertEquals($testValue, $this->user->encryption_test_value);
        $this->assertFalse($this->user->vault_locked);
    }

    /**
     * Test cannot setup encryption twice
     */
    public function test_cannot_setup_encryption_twice(): void
    {
        // First setup
        $this->encryptionService->setupEncryption(
            $this->user,
            'salt1',
            '{"test":"value1"}',
            1
        );

        // Try to setup again
        $result = $this->encryptionService->setupEncryption(
            $this->user,
            'salt2',
            '{"test":"value2"}',
            2
        );

        $this->assertFalse($result);
        $this->user->refresh();
        $this->assertEquals('salt1', $this->user->encryption_salt);
    }

    /**
     * Test getting encryption info
     */
    public function test_get_encryption_info_returns_correct_data(): void
    {
        $this->user->encryption_salt = 'test_salt';
        $this->user->encryption_test_value = '{"test":"value"}';
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();

        $info = $this->encryptionService->getEncryptionInfo($this->user);

        $this->assertTrue($info['encryption_enabled']);
        $this->assertEquals('test_salt', $info['encryption_salt']);
        $this->assertEquals('{"test":"value"}', $info['encryption_test_value']);
        $this->assertEquals(1, $info['encryption_version']);
        $this->assertTrue($info['vault_locked']);
    }

    /**
     * Test getting encryption info when not enabled
     */
    public function test_get_encryption_info_when_not_enabled(): void
    {
        $info = $this->encryptionService->getEncryptionInfo($this->user);

        $this->assertFalse($info['encryption_enabled']);
        $this->assertArrayNotHasKey('encryption_salt', $info);
    }

    /**
     * Test vault locking
     */
    public function test_can_lock_vault(): void
    {
        $this->user->encryption_version = 1;
        $this->user->vault_locked = false;
        $this->user->save();

        $result = $this->encryptionService->lockVault($this->user);

        $this->assertTrue($result);
        $this->user->refresh();
        $this->assertTrue($this->user->vault_locked);
    }

    /**
     * Test cannot lock vault when encryption not enabled
     */
    public function test_cannot_lock_vault_when_encryption_not_enabled(): void
    {
        $result = $this->encryptionService->lockVault($this->user);

        $this->assertFalse($result);
    }

    /**
     * Test vault unlocking
     */
    public function test_can_unlock_vault(): void
    {
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();

        $result = $this->encryptionService->unlockVault($this->user);

        $this->assertTrue($result);
        $this->user->refresh();
        $this->assertFalse($this->user->vault_locked);
    }

    /**
     * Test disabling encryption
     */
    public function test_can_disable_encryption(): void
    {
        $this->user->encryption_salt = 'salt';
        $this->user->encryption_test_value = 'test';
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();

        $result = $this->encryptionService->disableEncryption($this->user);

        $this->assertTrue($result);
        $this->user->refresh();

        $this->assertNull($this->user->encryption_salt);
        $this->assertNull($this->user->encryption_test_value);
        $this->assertEquals(0, $this->user->encryption_version);
        $this->assertFalse($this->user->vault_locked);
    }

    /**
     * Test getting encryption status
     */
    public function test_get_encryption_status(): void
    {
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->last_backup_at = now();
        $this->user->save();

        $status = $this->encryptionService->getEncryptionStatus($this->user);

        $this->assertTrue($status['encryption_enabled']);
        $this->assertEquals(1, $status['encryption_version']);
        $this->assertTrue($status['vault_locked']);
        $this->assertTrue($status['has_backup']);
        $this->assertNotNull($status['last_backup_at']);
    }

    /**
     * Test updating encryption credentials (for password change)
     */
    public function test_can_update_encryption_credentials(): void
    {
        $this->user->encryption_salt = 'old_salt';
        $this->user->encryption_test_value = 'old_test';
        $this->user->encryption_version = 1;
        $this->user->save();

        $result = $this->encryptionService->updateEncryptionCredentials(
            $this->user,
            'new_salt',
            '{"new":"test"}',
            2
        );

        $this->assertTrue($result);
        $this->user->refresh();

        $this->assertEquals('new_salt', $this->user->encryption_salt);
        $this->assertEquals('{"new":"test"}', $this->user->encryption_test_value);
        $this->assertEquals(2, $this->user->encryption_version);
    }

    /**
     * Test cannot update credentials when encryption not enabled
     */
    public function test_cannot_update_credentials_when_encryption_not_enabled(): void
    {
        $result = $this->encryptionService->updateEncryptionCredentials(
            $this->user,
            'new_salt',
            '{"test":"value"}',
            1
        );

        $this->assertFalse($result);
    }

    /**
     * Test validating encrypted payload structure
     */
    public function test_validate_encrypted_payload_structure(): void
    {
        $validPayload = json_encode([
            'ciphertext' => base64_encode('secret'),
            'iv' => base64_encode('iv123'),
            'authTag' => base64_encode('tag123'),
        ]);

        $this->assertTrue(
            $this->encryptionService->validateEncryptedPayload($validPayload)
        );
    }

    /**
     * Test invalid encrypted payload is rejected
     */
    public function test_invalid_encrypted_payload_is_rejected(): void
    {
        // Missing ciphertext
        $invalid1 = json_encode([
            'iv' => base64_encode('iv123'),
            'authTag' => base64_encode('tag123'),
        ]);

        $this->assertFalse(
            $this->encryptionService->validateEncryptedPayload($invalid1)
        );

        // Non-string ciphertext
        $invalid2 = json_encode([
            'ciphertext' => 123,
            'iv' => base64_encode('iv123'),
            'authTag' => base64_encode('tag123'),
        ]);

        $this->assertFalse(
            $this->encryptionService->validateEncryptedPayload($invalid2)
        );

        // Not valid JSON
        $invalid3 = 'not json at all';

        $this->assertFalse(
            $this->encryptionService->validateEncryptedPayload($invalid3)
        );
    }

    /**
     * Test storing encrypted secret for TwoFAccount
     */
    public function test_can_store_encrypted_secret(): void
    {
        $account = new TwoFAccount();
        $account->user_id = $this->user->id;
        $account->service = 'TestService';
        $account->account = 'test@example.com';
        $account->otp_type = 'totp';

        $encryptedSecret = json_encode([
            'ciphertext' => base64_encode('encrypted_secret'),
            'iv' => base64_encode('iv123'),
            'authTag' => base64_encode('tag123'),
        ]);

        $result = $this->encryptionService->storeEncryptedSecret($account, $encryptedSecret);

        $this->assertTrue($result);
        $account->refresh();

        $this->assertEquals($encryptedSecret, $account->secret);
        $this->assertTrue($account->encrypted);
    }

    /**
     * Test getting encryption stats
     */
    public function test_get_encryption_stats(): void
    {
        // Create encrypted accounts
        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
        ]);

        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
        ]);

        // Create unencrypted account
        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => false,
        ]);

        $stats = $this->encryptionService->getEncryptionStats($this->user);

        $this->assertEquals(3, $stats['total_accounts']);
        $this->assertEquals(2, $stats['encrypted_accounts']);
        $this->assertEquals(1, $stats['unencrypted_accounts']);
        $this->assertFalse($stats['fully_encrypted']);
    }

    /**
     * Test bulk update encrypted secrets
     */
    public function test_can_bulk_update_encrypted_secrets(): void
    {
        $account1 = TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
        ]);

        $account2 = TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
        ]);

        $newSecret1 = json_encode([
            'ciphertext' => base64_encode('new_secret1'),
            'iv' => base64_encode('new_iv1'),
            'authTag' => base64_encode('new_tag1'),
        ]);

        $newSecret2 = json_encode([
            'ciphertext' => base64_encode('new_secret2'),
            'iv' => base64_encode('new_iv2'),
            'authTag' => base64_encode('new_tag2'),
        ]);

        $result = $this->encryptionService->bulkUpdateEncryptedSecrets($this->user, [
            ['id' => $account1->id, 'secret' => $newSecret1],
            ['id' => $account2->id, 'secret' => $newSecret2],
        ]);

        $this->assertEquals(2, $result['updated']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEmpty($result['errors']);

        $account1->refresh();
        $account2->refresh();

        $this->assertEquals($newSecret1, $account1->secret);
        $this->assertEquals($newSecret2, $account2->secret);
    }

    /**
     * Test bulk update handles invalid account IDs
     */
    public function test_bulk_update_handles_invalid_account_ids(): void
    {
        $result = $this->encryptionService->bulkUpdateEncryptedSecrets($this->user, [
            ['id' => 99999, 'secret' => '{"test":"value"}'],
        ]);

        $this->assertEquals(0, $result['updated']);
        $this->assertEquals(1, $result['failed']);
        $this->assertNotEmpty($result['errors']);
    }

    /**
     * Test bulk update validates payload structure
     */
    public function test_bulk_update_validates_payload_structure(): void
    {
        $account = TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
        ]);

        $result = $this->encryptionService->bulkUpdateEncryptedSecrets($this->user, [
            ['id' => $account->id, 'secret' => 'invalid_json_structure'],
        ]);

        $this->assertEquals(0, $result['updated']);
        $this->assertEquals(1, $result['failed']);
        $this->assertStringContainsString('Invalid encrypted payload', $result['errors'][0]);
    }
}
