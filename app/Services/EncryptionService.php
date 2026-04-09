<?php

namespace App\Services;

use App\Models\User;
use App\Models\TwoFAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EncryptionService
 *
 * Manages the server-side aspects of E2EE (End-to-End Encryption).
 *
 * ZERO-KNOWLEDGE ARCHITECTURE:
 * - The server NEVER receives the master password or derived encryption key
 * - The server stores only: salt (for key derivation), test value (for verification), version
 * - All encryption/decryption happens CLIENT-SIDE using Web Crypto API
 * - The server handles: setup, status checks, vault lock state, encrypted data storage
 *
 * Client-side crypto (handled by frontend crypto.js):
 * - Key derivation: Argon2id (password + salt -> encryption key)
 * - Encryption: AES-256-GCM (key + plaintext -> {ciphertext, iv, authTag})
 * - The encrypted payload format: {"ciphertext":"base64","iv":"base64","authTag":"base64"}
 */
class EncryptionService
{
    /**
     * Current encryption version
     */
    const CURRENT_VERSION = 1;

    /**
     * Setup E2EE for a user
     *
     * Stores the salt and test value that the client generated.
     * The server never sees the password or encryption key.
     *
     * @param User $user
     * @param string $salt Base64-encoded salt for Argon2id key derivation
     * @param string $testValue Encrypted test value for zero-knowledge verification
     * @param int $version Encryption version
     * @return bool
     */
    public function setupEncryption(User $user, string $salt, string $testValue, int $version = self::CURRENT_VERSION): bool
    {
        if ($user->encryption_version > 0) {
            return false;
        }

        $user->encryption_enabled = true;
        $user->encryption_salt = $salt;
        $user->encryption_test_value = $testValue;
        $user->encryption_version = $version;
        $user->vault_locked = true;

        return $user->save();
    }

    /**
     * Check if user has E2EE enabled
     *
     * @param User $user
     * @return bool
     */
    public function isEncryptionEnabled(User $user): bool
    {
        return $user->encryption_enabled === true
            && $user->encryption_version > 0
            && !is_null($user->encryption_salt)
            && !is_null($user->encryption_test_value);
    }

    /**
     * Get encryption info for a user (salt + test value for client-side key derivation)
     *
     * @param User $user
     * @return array
     */
    public function getEncryptionInfo(User $user): array
    {
        if (!$this->isEncryptionEnabled($user)) {
            return ['encryption_enabled' => false];
        }

        return [
            'encryption_enabled' => true,
            'encryption_salt' => $user->encryption_salt,
            'encryption_test_value' => $user->encryption_test_value,
            'encryption_version' => $user->encryption_version,
            'vault_locked' => $user->vault_locked,
        ];
    }

    /**
     * Lock the vault
     *
     * @param User $user
     * @return bool
     */
    public function lockVault(User $user): bool
    {
        if (!$this->isEncryptionEnabled($user)) {
            return false;
        }

        $user->vault_locked = true;
        return $user->save();
    }

    /**
     * Unlock the vault after successful client-side verification
     *
     * @param User $user
     * @return bool
     */
    public function unlockVault(User $user): bool
    {
        $user->vault_locked = false;
        return $user->save();
    }

    /**
     * Disable E2EE for a user
     *
     * WARNING: This should only be called after the client has re-encrypted
     * all data or the user confirms data loss.
     *
     * @param User $user
     * @return bool
     */
    public function disableEncryption(User $user): bool
    {
        $user->encryption_enabled = false;
        $user->encryption_salt = null;
        $user->encryption_test_value = null;
        $user->encryption_version = 0;
        $user->vault_locked = false;

        return $user->save();
    }

    /**
     * Get encryption status summary
     *
     * @param User $user
     * @return array
     */
    public function getEncryptionStatus(User $user): array
    {
        $encryptionEnabled = $this->isEncryptionEnabled($user);
        $e2eeRequired = $this->isEncryptionRequired($user);

        return [
            'encryption_enabled' => $encryptionEnabled,
            'encryption_version' => $user->encryption_version,
            'vault_locked' => $encryptionEnabled ? $user->vault_locked : false,
            'has_backup' => !is_null($user->last_backup_at),
            'last_backup_at' => $user->last_backup_at?->toIso8601String(),
            'e2ee_required' => $e2eeRequired,
            'should_prompt_setup' => !$encryptionEnabled && $e2eeRequired,
        ];
    }

    public function isEncryptionRequired(User $user): bool
    {
        return config('2fauth.settings.enforceMandatoryEncryption', false)
            && !$this->isEncryptionEnabled($user);
    }

    /**
     * Update encryption salt (for password change / re-encryption)
     *
     * When a user changes their master password:
     * 1. Client derives new key from new password + new salt
     * 2. Client re-encrypts test value with new key
     * 3. Client sends new salt + new test value here
     * 4. Client re-encrypts all secrets with new key (separate endpoint)
     *
     * @param User $user
     * @param string $newSalt New base64-encoded salt
     * @param string $newTestValue New encrypted test value
     * @param int|null $newVersion Optional new encryption version
     * @return bool
     */
    public function updateEncryptionCredentials(User $user, string $newSalt, string $newTestValue, ?int $newVersion = null): bool
    {
        if (!$this->isEncryptionEnabled($user)) {
            return false;
        }

        $user->encryption_salt = $newSalt;
        $user->encryption_test_value = $newTestValue;

        if ($newVersion !== null) {
            $user->encryption_version = $newVersion;
        }

        return $user->save();
    }

    /**
     * Store an encrypted secret for a TwoFAccount
     *
     * The server receives the encrypted payload from the client and stores it as-is.
     * The server NEVER decrypts this data.
     *
     * @param TwoFAccount $account
     * @param string $encryptedSecret JSON string: {"ciphertext":"...","iv":"...","authTag":"..."}
     * @return bool
     */
    public function storeEncryptedSecret(TwoFAccount $account, string $encryptedSecret): bool
    {
        $account->secret = $encryptedSecret;
        $account->encrypted = true;

        return $account->save();
    }

    /**
     * Validate encrypted payload structure
     *
     * Checks that the payload has the expected format without decrypting it.
     *
     * @param string $payload
     * @return bool
     */
    public function validateEncryptedPayload(string $payload): bool
    {
        $data = json_decode($payload, true);

        if (!is_array($data)) {
            return false;
        }

        $requiredFields = ['ciphertext', 'iv', 'authTag'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bulk update encrypted secrets (for re-encryption on password change)
     *
     * The client re-encrypts all secrets with the new key and sends them here.
     *
     * @param User $user
     * @param array $encryptedAccounts Array of [{id: int, secret: string}]
     * @return array Results with counts
     */
    public function bulkUpdateEncryptedSecrets(User $user, array $encryptedAccounts): array
    {
        $updated = 0;
        $failed = 0;
        $errors = [];

        DB::beginTransaction();

        try {
            foreach ($encryptedAccounts as $accountData) {
                try {
                    $account = TwoFAccount::where('id', $accountData['id'])
                        ->where('user_id', $user->id)
                        ->first();

                    if (!$account) {
                        $failed++;
                        $errors[] = "Account {$accountData['id']} not found";
                        continue;
                    }

                    if (!$this->validateEncryptedPayload($accountData['secret'])) {
                        $failed++;
                        $errors[] = "Invalid encrypted payload for account {$accountData['id']}";
                        continue;
                    }

                    $account->secret = $accountData['secret'];
                    $account->encrypted = true;
                    $account->save();
                    $updated++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Failed to update account {$accountData['id']}: {$e->getMessage()}";
                }
            }

            DB::commit();

            return [
                'updated' => $updated,
                'failed' => $failed,
                'errors' => $errors,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Get count of encrypted vs unencrypted accounts for a user
     *
     * @param User $user
     * @return array
     */
    public function getEncryptionStats(User $user): array
    {
        $total = TwoFAccount::where('user_id', $user->id)->count();
        $encrypted = TwoFAccount::where('user_id', $user->id)->where('encrypted', true)->count();

        return [
            'total_accounts' => $total,
            'encrypted_accounts' => $encrypted,
            'unencrypted_accounts' => $total - $encrypted,
            'fully_encrypted' => $total > 0 && $encrypted === $total,
        ];
    }
}
