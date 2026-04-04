<?php

namespace App\Services;

use App\Models\User;
use App\Models\TwoFAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BackupService
 * 
 * Handles encrypted backup/restore for 2FAuth.
 * 
 * Import from other apps:
 * - Aegis: Use AegisMigrator (app/Services/Migrators/AegisMigrator.php)
 * - 2FAS: Use TwoFASMigrator (app/Services/Migrators/TwoFASMigrator.php)
 * - Google Authenticator: Use GoogleAuthMigrator (app/Services/Migrators/GoogleAuthMigrator.php)
 * - Bitwarden: Use BitwardenMigrator (app/Services/Migrators/BitwardenMigrator.php)
 * 
 * All migrators support encrypted imports natively.
 */
class BackupService
{
    /**
     * Generate encrypted backup for user
     * 
     * NOTE: This generates the backup structure.
     * Client-side JavaScript will handle encryption before download.
     * 
     * @param User $user
     * @return array
     */
    public function generateEncryptedBackup(User $user): array
    {
        // Fetch all 2FA accounts for the user
        $accounts = TwoFAccount::where('user_id', $user->id)
            ->with('group')
            ->get()
            ->map(function ($account) {
                return [
                    'id' => $account->id,
                    'service' => $account->service,
                    'account' => $account->account,
                    'secret' => $account->secret,
                    'algorithm' => $account->algorithm,
                    'digits' => $account->digits,
                    'period' => $account->period,
                    'counter' => $account->counter,
                    'otp_type' => $account->otp_type,
                    'icon' => $account->icon,
                    'group_id' => $account->group_id,
                    'group_name' => $account->group?->name,
                    'created_at' => $account->created_at?->toIso8601String(),
                    'updated_at' => $account->updated_at?->toIso8601String(),
                ];
            })
            ->toArray();
        
        // Backup structure (will be encrypted by client)
        return [
            'version' => '2.0',
            'encrypted' => true,
            'encryption_version' => $user->encryption_version,
            'app' => '2FAuth',
            'exportedAt' => now()->toIso8601String(),
            'accountCount' => count($accounts),
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'name' => $user->name,
            ],
            // This will be encrypted by client before download
            'accounts' => $accounts,
        ];
    }

    /**
     * Restore encrypted backup
     * 
     * @param User $user
     * @param array $backupData Decrypted backup data from client
     * @param string $mode 'merge' or 'replace'
     * @return array
     */
    public function restoreEncryptedBackup(User $user, array $backupData, string $mode = 'merge'): array
    {
        $imported = 0;
        $failed = 0;
        $errors = [];
        
        DB::beginTransaction();
        
        try {
            // If replace mode, delete existing accounts
            if ($mode === 'replace') {
                TwoFAccount::where('user_id', $user->id)->delete();
                Log::info('Existing accounts deleted for replace mode', ['user_id' => $user->id]);
            }
            
            // Import accounts
            foreach ($backupData['accounts'] as $accountData) {
                try {
                    // Check if account already exists (in merge mode)
                    if ($mode === 'merge') {
                        $exists = TwoFAccount::where('user_id', $user->id)
                            ->where('service', $accountData['service'])
                            ->where('account', $accountData['account'])
                            ->exists();
                        
                        if ($exists) {
                            Log::info('Account skipped (already exists)', [
                                'service' => $accountData['service'],
                                'account' => $accountData['account']
                            ]);
                            continue;
                        }
                    }
                    
                    // Create new account
                    $account = new TwoFAccount();
                    $account->user_id = $user->id;
                    $account->service = $accountData['service'] ?? 'Unknown';
                    $account->account = $accountData['account'] ?? '';
                    $account->secret = $accountData['secret'];
                    $account->algorithm = $accountData['algorithm'] ?? 'sha1';
                    $account->digits = $accountData['digits'] ?? 6;
                    $account->period = $accountData['period'] ?? 30;
                    $account->counter = $accountData['counter'] ?? null;
                    $account->otp_type = $accountData['otp_type'] ?? 'totp';
                    $account->icon = $accountData['icon'] ?? null;
                    $account->group_id = $accountData['group_id'] ?? null;
                    
                    $account->save();
                    
                    $imported++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = [
                        'service' => $accountData['service'] ?? 'Unknown',
                        'error' => $e->getMessage()
                    ];
                    
                    Log::error('Failed to import account', [
                        'service' => $accountData['service'] ?? 'Unknown',
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            DB::commit();
            
            return [
                'imported' => $imported,
                'failed' => $failed,
                'errors' => $errors
            ];
            
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /**
     * Validate backup file structure
     * 
     * @param array|null $backupData
     * @return bool
     */
    public function validateBackupFile(?array $backupData): bool
    {
        if (!is_array($backupData)) {
            return false;
        }
        
        // Required fields
        $requiredFields = ['version', 'encrypted', 'accounts'];
        foreach ($requiredFields as $field) {
            if (!isset($backupData[$field])) {
                return false;
            }
        }
        
        // Validate accounts structure
        if (!is_array($backupData['accounts'])) {
            return false;
        }
        
        // Check if encrypted backup
        if ($backupData['encrypted'] !== true) {
            Log::warning('Backup file is not encrypted');
            return false;
        }
        
        return true;
    }

    /**
     * Get backup metadata without decrypting
     * 
     * @param array $backupData
     * @return array
     */
    public function getBackupMetadata(array $backupData): array
    {
        return [
            'version' => $backupData['version'] ?? 'unknown',
            'app' => $backupData['app'] ?? 'Unknown',
            'encrypted' => $backupData['encrypted'] ?? false,
            'encryption_version' => $backupData['encryption_version'] ?? 0,
            'exportedAt' => $backupData['exportedAt'] ?? null,
            'accountCount' => $backupData['accountCount'] ?? 0,
            'user' => $backupData['user'] ?? null,
        ];
    }
}
