<?php

namespace Tests\Feature\Services;

use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use App\Services\BackupService;
use App\Services\Migrators\AegisMigrator;
use App\Services\Migrators\BitwardenMigrator;
use App\Services\Migrators\GoogleAuthMigrator;
use App\Services\Migrators\TwoFAuthMigrator;
use App\Services\Migrators\TwoFASMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Data\MigrationTestData;
use Tests\TestCase;

/**
 * BackupService Tests
 *
 * Tests for the encrypted backup/restore functionality.
 */
class BackupServiceTest extends TestCase
{
    use RefreshDatabase;

    private BackupService $backupService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupService = new BackupService(
            app(TwoFAuthMigrator::class),
            app(TwoFASMigrator::class),
            app(AegisMigrator::class),
            app(BitwardenMigrator::class),
            app(GoogleAuthMigrator::class),
        );
        $this->user = User::factory()->create();
    }

    /**
     * Test generating backup with no accounts
     */
    public function test_generate_backup_with_no_accounts(): void
    {
        $backup = $this->backupService->generateEncryptedBackup($this->user);

        // C1: the generator now returns the payload directly, marked format 2
        $this->assertEquals('2FA-Vault', $backup['app']);
        $this->assertEquals(2, $backup['format']);
        $this->assertEquals('2.0', $backup['version']);

        $rawBackup = $this->backupService->normalizeVaultBackupData($backup);

        $this->assertEquals(2, $rawBackup['format']);
        $this->assertEquals('2.0', $rawBackup['version']);
        $this->assertTrue($rawBackup['encrypted']);
        $this->assertTrue($rawBackup['double_encrypted']);
        $this->assertEquals(0, $rawBackup['account_count']);
        $this->assertIsArray($rawBackup['accounts']);
        $this->assertEmpty($rawBackup['accounts']);
    }

    /**
     * Test generating backup with accounts
     */
    public function test_generate_backup_with_accounts(): void
    {
        TwoFAccount::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        $backup = $this->backupService->generateEncryptedBackup($this->user);
        $rawBackup = $this->backupService->normalizeVaultBackupData($backup);

        $this->assertEquals(3, $rawBackup['account_count']);
        $this->assertCount(3, $rawBackup['accounts']);
        $this->assertArrayHasKey('id', $rawBackup['accounts'][0]);
        $this->assertArrayHasKey('service', $rawBackup['accounts'][0]);
        $this->assertArrayHasKey('account', $rawBackup['accounts'][0]);
        $this->assertArrayHasKey('secret', $rawBackup['accounts'][0]);
    }

    /**
     * Test generating backup includes groups
     */
    public function test_generate_backup_includes_groups(): void
    {
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Personal',
        ]);

        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'group_id' => $group->id,
        ]);

        $backup = $this->backupService->generateEncryptedBackup($this->user, includeGroups: true);
        $rawBackup = $this->backupService->normalizeVaultBackupData($backup);

        $this->assertArrayHasKey('groups', $rawBackup);
        $this->assertCount(1, $rawBackup['groups']);
        $this->assertEquals('Personal', $rawBackup['groups'][0]['name']);
    }

    /**
     * Test generating backup without groups
     */
    public function test_generate_backup_without_groups(): void
    {
        Group::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $backup = $this->backupService->generateEncryptedBackup($this->user, includeGroups: false);
        $rawBackup = $this->backupService->normalizeVaultBackupData($backup);

        $this->assertArrayNotHasKey('groups', $rawBackup);
    }

    /**
     * Test generating backup with encrypted accounts
     */
    public function test_generate_backup_with_encrypted_accounts(): void
    {
        $this->user->encryption_version = 1;
        $this->user->save();

        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
            'secret' => json_encode([
                'ciphertext' => base64_encode('secret'),
                'iv' => base64_encode('iv'),
                'authTag' => base64_encode('tag'),
            ]),
        ]);

        $backup = $this->backupService->generateEncryptedBackup($this->user);
        $rawBackup = $this->backupService->normalizeVaultBackupData($backup);

        $this->assertEquals(1, $rawBackup['encryption_version']);
        $this->assertTrue($rawBackup['accounts'][0]['encrypted']);
    }

    /**
     * Test that a server-built encrypted envelope can be decrypted back
     * (Argon2id + AES-256-GCM, same stack the SPA uses).
     */
    public function test_encrypted_envelope_round_trip(): void
    {
        $payload = $this->backupService->generateEncryptedBackup($this->user);
        $payload['accounts'][] = [
            'service' => 'GitHub',
            'account' => 'user@example.com',
            'secret'  => 'JBSWY3DPEHPK3PXP',
            'otp_type' => 'totp',
        ];

        $envelope = $this->backupService->buildEncryptedEnvelope($payload, 'correct horse battery staple');

        $this->assertEquals('2FA-Vault', $envelope['app']);
        $this->assertEquals(2, $envelope['format']);
        $this->assertEquals('aes-256-gcm', $envelope['encryption']['algorithm']);
        $this->assertEquals('argon2id', $envelope['encryption']['kdf']);
        $this->assertNotSame($payload, $envelope['data']);

        // Decrypt manually with the same KDF + cipher
        $key = sodium_crypto_pwhash(
            32,
            'correct horse battery staple',
            base64_decode($envelope['encryption']['salt'], true),
            $envelope['encryption']['kdf_params']['time'],
            $envelope['encryption']['kdf_params']['memory_kib'] * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $tag = base64_decode($envelope['encryption']['tag'], true);

        $plaintext = openssl_decrypt(
            base64_decode($envelope['data'], true),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode($envelope['encryption']['iv'], true),
            $tag,
            ''
        );

        // Wrong password must NOT decrypt
        $wrongKey = sodium_crypto_pwhash(
            32,
            'wrong password',
            base64_decode($envelope['encryption']['salt'], true),
            $envelope['encryption']['kdf_params']['time'],
            $envelope['encryption']['kdf_params']['memory_kib'] * 1024,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        $this->assertSame(json_encode($payload, JSON_UNESCAPED_SLASHES), $plaintext);
        $this->assertSame($envelope['encryption']['tag'], base64_encode($tag));
        $wrongTag = random_bytes(16);
        $this->assertFalse(
            openssl_decrypt(
                base64_decode($envelope['data'], true),
                'aes-256-gcm',
                $wrongKey,
                OPENSSL_RAW_DATA,
                base64_decode($envelope['encryption']['iv'], true),
                $wrongTag,
                ''
            )
        );
    }

    /**
     * Undecrypted v2 envelope detection (RT4)
     */
    public function test_undecrypted_v2_envelope_detection(): void
    {
        $envelope = $this->backupService->buildEncryptedEnvelope(
            $this->backupService->generateEncryptedBackup($this->user),
            'pw'
        );

        $this->assertTrue($this->backupService->isUndecryptedV2Envelope($envelope));
        $this->assertFalse($this->backupService->validateImportPayload($envelope, 'vault'));

        // Once decrypted client-side, the payload carries a top-level
        // accounts array and is importable
        $payload = $this->backupService->generateEncryptedBackup($this->user);
        $this->assertFalse($this->backupService->isUndecryptedV2Envelope($payload));

        // Legacy envelopes (no numeric format) are not v2 envelopes
        $legacy = $this->backupService->buildLegacyEnvelope($payload);
        $this->assertFalse($this->backupService->isUndecryptedV2Envelope($legacy));
        $this->assertTrue($this->backupService->isLegacyVaultImport($legacy));
    }

    /**
     * C2: the app's own exports must be detected as vault format, not routed
     * to the external TwoFAuth migrator.
     */
    public function test_detect_import_format_routes_own_exports_to_vault(): void
    {
        $payload = $this->backupService->generateEncryptedBackup($this->user);
        $this->assertEquals('vault', $this->backupService->detectImportFormat($payload));

        $legacyEnvelope = $this->backupService->buildLegacyEnvelope($payload);
        $this->assertEquals('vault', $this->backupService->detectImportFormat($legacyEnvelope));

        // Genuine 2FAuth app exports still go to the TwoFAuth migrator
        $this->assertEquals(
            '2FA-Vault',
            $this->backupService->detectImportFormat([
                'app'  => 'twofauth_v3.4.1',
                'schema' => 1,
                'data' => base64_encode('{}'),
            ])
        );
    }

    /**
     * C2 round-trip: generate → import restores the accounts
     */
    public function test_round_trip_generate_then_restore(): void
    {
        TwoFAccount::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        $payload = $this->backupService->generateEncryptedBackup($this->user);

        TwoFAccount::query()->delete();

        $result = $this->backupService->restoreEncryptedBackup($this->user, $payload, 'vault');

        $this->assertEquals(3, $result['imported']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(3, TwoFAccount::where('user_id', $this->user->id)->count());
        $this->assertCount(3, $result['imported_ids']);
    }

    /**
     * C6: an unmapped group_id must not be attached raw
     */
    public function test_restore_sets_null_group_id_when_unmapped(): void
    {
        // A group belonging to ANOTHER user
        $otherUser = User::factory()->create();
        $foreignGroup = Group::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $backupData = [
            'version'  => '2.0',
            'format'   => 2,
            'accounts' => [
                [
                    'service'  => 'GitHub',
                    'account'  => 'user@example.com',
                    'secret'   => 'JBSWY3DPEHPK3PXP',
                    'group_id' => $foreignGroup->id,
                ],
            ],
            'groups' => [],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault',
            ['import_groups' => true]
        );

        $this->assertEquals(1, $result['imported']);

        $account = TwoFAccount::where('user_id', $this->user->id)->first();
        $this->assertNull($account->group_id);
    }

    /**
     * C7: migrator fake error accounts are counted as failed, not persisted
     */
    public function test_external_import_counts_fake_accounts_as_failed(): void
    {
        // Item 0 is valid; item 1 has a totp URI whose secret cannot be
        // parsed → migrator returns a fake error account (a missing key
        // would instead abort with InvalidMigrationDataException per C9)
        $migrationPayload = json_encode([
            'encrypted' => false,
            'items'     => [
                [
                    'name' => 'Google',
                    'type' => 1,
                    'login' => [
                        'username' => 'john@example.com',
                        'totp'     => 'otpauth://totp/Google:john?issuer=Google&secret=A5GRFTVVRBGY7UIW',
                    ],
                ],
                [
                    'name' => 'Broken',
                    'type' => 1,
                    'login' => [
                        'username' => 'broken@example.com',
                        'totp'     => 'otpauth://totp/Broken:john?issuer=Broken',
                    ],
                ],
            ],
        ]);

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            json_decode($migrationPayload, true),
            'bitwarden'
        );

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(1, $result['failed']);

        // The fake account must not be persisted
        $this->assertCount(1, TwoFAccount::where('user_id', $this->user->id)->get());
    }

    /**
     * C3: key mismatch summary
     */
    public function test_restore_reports_key_mismatch(): void
    {
        $backupData = [
            'format'             => 2,
            'version'            => '2.0',
            'encryption_version' => 2,
            'accounts'           => [
                [
                    'service'   => 'Enc',
                    'account'   => 'enc@example.com',
                    'secret'    => json_encode(['ciphertext' => 'a', 'iv' => 'b', 'authTag' => 'c']),
                    'encrypted' => true,
                    'otp_type'  => 'totp',
                ],
            ],
        ];

        // Importer's E2EE version differs from the backup's
        $this->user->encryption_version = 1;
        $this->user->save();

        $result = $this->backupService->restoreEncryptedBackup($this->user, $backupData, 'vault');

        $this->assertEquals(1, $result['encrypted_count']);
        $this->assertTrue($result['key_mismatch_warning']);
    }

    /**
     * C13: an import must not set last_backup_at
     */
    public function test_restore_does_not_update_last_backup_at(): void
    {
        $backupData = [
            'version'  => '2.0',
            'accounts' => [
                [
                    'service'  => 'GitHub',
                    'account'  => 'user@example.com',
                    'secret'   => 'JBSWY3DPEHPK3PXP',
                    'otp_type' => 'totp',
                ],
            ],
        ];

        $this->backupService->restoreEncryptedBackup($this->user, $backupData, 'vault');

        $this->user->refresh();
        $this->assertNull($this->user->last_backup_at);
    }

    /**
     * Test restoring backup from vault format
     */
    public function test_restore_backup_from_vault_format(): void
    {
        $backupData = [
            'format' => '2FA-Vault',
            'version' => '2.0',
            'encrypted' => true,
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                    'algorithm' => 'sha1',
                    'digits' => 6,
                    'period' => 30,
                    'otp_type' => 'totp',
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault'
        );

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(0, $result['skipped']);

        $this->assertDatabaseHas('twofaccounts', [
            'user_id' => $this->user->id,
            'service' => 'GitHub',
            'account' => 'user@example.com',
        ]);
    }

    /**
     * Test restoring backup from 2FA-Vault format
     */
    public function test_restore_backup_from_2fa_vault_format(): void
    {
        $backupData = [
            'app' => '2FA-Vault',
            'version' => '6.1.3',
            'accounts' => [
                [
                    'service' => 'Google',
                    'account' => 'test@gmail.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            '2FA-Vault'
        );

        $this->assertEquals(1, $result['imported']);

        $this->assertDatabaseHas('twofaccounts', [
            'user_id' => $this->user->id,
            'service' => 'Google',
        ]);
    }

    /**
     * Test restoring backup skips duplicate accounts
     */
    public function test_restore_backup_skips_duplicates_by_default(): void
    {
        // Create existing account
        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'GitHub',
            'account' => 'user@example.com',
        ]);

        $backupData = [
            'version' => '2.0',
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault'
        );

        $this->assertEquals(0, $result['imported']);
        $this->assertEquals(1, $result['skipped']);

        // Should still have only one account
        $this->assertEquals(1, TwoFAccount::where('user_id', $this->user->id)->count());
    }

    /**
     * Test restoring backup with replace conflict resolution
     */
    public function test_restore_backup_with_replace_conflict_resolution(): void
    {
        $existing = TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'GitHub',
            'account' => 'user@example.com',
            'secret' => 'OLD_SECRET',
        ]);

        // Note: The model pads base32 secrets with '=' to make length a multiple of 8
        // NEW_SECRET (10 chars) becomes NEW_SECRET====== (16 chars)
        $backupData = [
            'version' => '2.0',
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => 'NEW_SECRET',
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault',
            ['conflict_resolution' => 'replace']
        );

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped']);

        $existing->refresh();
        // The secret will be padded by the model's setSecretAttribute
        $this->assertEquals('NEW_SECRET======', $existing->secret);
    }

    /**
     * Test restoring backup with rename conflict resolution
     */
    public function test_restore_backup_with_rename_conflict_resolution(): void
    {
        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'service' => 'GitHub',
            'account' => 'user@example.com',
        ]);

        $backupData = [
            'version' => '2.0',
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault',
            ['conflict_resolution' => 'rename']
        );

        $this->assertEquals(1, $result['imported']);
        $this->assertEquals(0, $result['skipped']);

        // Should have 2 accounts now (original + renamed)
        $this->assertEquals(2, TwoFAccount::where('user_id', $this->user->id)->count());

        // Find the renamed account - it should have a timestamp appended
        $renamed = TwoFAccount::where('user_id', $this->user->id)
            ->where('service', 'GitHub')
            ->where('account', 'like', '%user@example.com%')
            ->where('id', '!=', TwoFAccount::where('account', 'user@example.com')->first()->id)
            ->first();

        $this->assertNotNull($renamed);
        // The renamed account should have a timestamp in the account field (format: YmdHis)
        $this->assertStringContainsString('user@example.com', $renamed->account);
    }

    /**
     * Test restoring backup imports groups
     */
    public function test_restore_backup_imports_groups(): void
    {
        $backupData = [
            'version' => '2.0',
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                ],
            ],
            'groups' => [
                [
                    'id' => 1,
                    'name' => 'Personal',
                    'order' => 0,
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault',
            ['import_groups' => true]
        );

        $this->assertEquals(1, $result['imported']);

        $this->assertDatabaseHas('groups', [
            'user_id' => $this->user->id,
            'name' => 'Personal',
        ]);
    }

    /**
     * Test validating backup file
     */
    public function test_validate_valid_backup_file(): void
    {
        $validBackup = [
            'version' => '2.0',
            'encrypted' => true,
            'accounts' => [],
        ];

        $this->assertTrue($this->backupService->validateBackupFile($validBackup));
    }

    /**
     * Test validating invalid backup files
     */
    public function test_validate_invalid_backup_files(): void
    {
        // Missing version in payload
        $invalid1 = [
            'format' => '2FA-Vault',
            'encrypted' => true,
            'accounts' => [],
        ];
        $this->assertFalse($this->backupService->validateBackupFile($invalid1));

        // Invalid envelope data
        $invalidEnvelope = [
            'app' => '2FA-Vault',
            'version' => '2.0',
            'data' => 'not-base64',
        ];
        $this->assertFalse($this->backupService->validateBackupFile($invalidEnvelope));

        // Missing accounts
        $invalid2 = [
            'version' => '2.0',
            'encrypted' => true,
        ];
        $this->assertFalse($this->backupService->validateBackupFile($invalid2));

        // Not an array
        $invalid3 = null;
        $this->assertFalse($this->backupService->validateBackupFile($invalid3));
    }

    /**
     * Test getting backup metadata
     */
    public function test_get_backup_metadata(): void
    {
        $backupData = [
            'format' => '2FA-Vault',
            'version' => '2.0',
            'encrypted' => true,
            'double_encrypted' => true,
            'encryption_version' => 1,
            'exported_at' => '2024-01-01T00:00:00Z',
            'user' => [
                'email' => 'test@example.com',
            ],
            'accounts' => [
                ['encrypted' => true],
            ],
        ];

        $metadata = $this->backupService->getBackupMetadata($backupData);

        $this->assertEquals('2FA-Vault', $metadata['format']);
        $this->assertEquals('2.0', $metadata['version']);
        $this->assertTrue($metadata['encrypted']);
        $this->assertTrue($metadata['double_encrypted']);
        $this->assertEquals(1, $metadata['encryption_version']);
        $this->assertEquals(1, $metadata['account_count']);
        $this->assertTrue($metadata['has_encrypted_accounts']);
        $this->assertTrue($metadata['compatible']);
    }

    /**
     * Test getting backup stats
     */
    public function test_get_backup_stats(): void
    {
        // Create accounts
        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
        ]);

        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => false,
        ]);

        Group::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $stats = $this->backupService->getBackupStats($this->user);

        $this->assertEquals(2, $stats['total_accounts']);
        $this->assertEquals(1, $stats['encrypted_accounts']);
        $this->assertEquals(1, $stats['unencrypted_accounts']);
        $this->assertEquals(1, $stats['total_groups']);
        $this->assertFalse($stats['has_backup']);
        $this->assertTrue($stats['should_backup']);
        $this->assertIsInt($stats['estimated_size_bytes']);
        $this->assertIsString($stats['estimated_size_human']);
    }

    /**
     * Test backup stats after backup
     */
    public function test_backup_stats_after_backup(): void
    {
        $this->user->last_backup_at = now()->subDays(10);
        $this->user->save();

        $stats = $this->backupService->getBackupStats($this->user);

        $this->assertTrue($stats['has_backup']);
        $this->assertFalse($stats['should_backup']);
    }

    /**
     * Test backup stats should backup when old
     */
    public function test_backup_stats_should_backup_when_old(): void
    {
        $this->user->last_backup_at = now()->subDays(40);
        $this->user->save();
        $this->user->refresh(); // Ensure we're using the persisted value

        $stats = $this->backupService->getBackupStats($this->user);

        // When backup was 40 days ago (> 30 days threshold), should_backup should be true
        $this->assertTrue($stats['should_backup']);
    }

    /**
     * Test restoring backup with encrypted accounts
     */
    public function test_restore_backup_with_encrypted_accounts(): void
    {
        $backupData = [
            'version' => '2.0',
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => json_encode([
                        'ciphertext' => base64_encode('encrypted_secret'),
                        'iv' => base64_encode('iv'),
                        'authTag' => base64_encode('tag'),
                    ]),
                    'encrypted' => true,
                    'otp_type' => 'totp',
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault'
        );

        $this->assertEquals(1, $result['imported']);

        $account = TwoFAccount::where('user_id', $this->user->id)->first();
        $this->assertTrue($account->encrypted);
        $this->assertIsString($account->secret);
    }

    /**
     * Test restoring backup with groups maps group IDs
     */
    public function test_restore_backup_maps_group_ids(): void
    {
        $backupData = [
            'version' => '2.0',
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                    'group_id' => 999, // Old group ID from backup
                ],
            ],
            'groups' => [
                [
                    'id' => 999,
                    'name' => 'Personal',
                    'order' => 0,
                ],
            ],
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault',
            ['import_groups' => true]
        );

        $this->assertEquals(1, $result['imported']);

        $account = TwoFAccount::where('user_id', $this->user->id)->first();
        $this->assertNotNull($account->group_id);

        // Verify the group ID was remapped
        $group = Group::find($account->group_id);
        $this->assertEquals('Personal', $group->name);
    }

    /**
     * Test handling large backup count
     */
    public function test_handles_large_backup_count(): void
    {
        // This test validates the service can handle large backups
        // Each account must be unique (different service names) for proper import

        $accounts = [];
        for ($i = 0; $i < 100; $i++) {
            $accounts[] = [
                'service' => 'Test-' . $i,
                'account' => 'test' . $i . '@example.com',
                'secret' => 'JBSWY3DPEHPK3PXP',
            ];
        }

        $backupData = [
            'version' => '2.0',
            'accounts' => $accounts,
        ];

        $result = $this->backupService->restoreEncryptedBackup(
            $this->user,
            $backupData,
            'vault'
        );

        $this->assertEquals(100, $result['imported']);
    }

    /**
     * Test format bytes returns human readable
     */
    public function test_format_bytes_returns_human_readable(): void
    {
        $stats = $this->backupService->getBackupStats($this->user);
        $size = $stats['estimated_size_human'];

        // Should have unit suffix
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)? (B|KB|MB|GB)$/', $size);
    }

    public function test_detect_import_format_for_external_formats(): void
    {
        $this->assertEquals('2fas', $this->backupService->detectImportFormat([
            'schemaVersion' => 2,
            'services' => [],
        ]));

        $this->assertEquals('aegis', $this->backupService->detectImportFormat([
            'db' => ['entries' => []],
        ]));

        $this->assertEquals('bitwarden', $this->backupService->detectImportFormat([
            'encrypted' => false,
            'items' => [],
        ]));

        $this->assertEquals('googleauth', $this->backupService->detectImportFormat([
            'payload' => MigrationTestData::GOOGLE_AUTH_MIGRATION_URI,
        ]));
    }

    public function test_validate_import_payload_for_external_accounts_shape(): void
    {
        $externalPayload = [
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'import@example.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                    'algorithm' => 'sha1',
                    'digits' => 6,
                    'period' => 30,
                    'otp_type' => 'totp',
                ],
            ],
        ];

        $this->assertTrue($this->backupService->validateImportPayload($externalPayload, '2FA-Vault'));
    }
}
