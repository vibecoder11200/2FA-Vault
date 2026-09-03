<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Team;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp() : void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('backups');

        $this->user = User::factory()->create([
            'email'                 => 'test@example.com',
            'encryption_enabled'    => true,
            'encryption_salt'       => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version'    => 1,
            'vault_locked'          => false,
        ]);

        $this->team = Team::factory()->create([
            'name'     => 'Test Team',
            'owner_id' => $this->user->id,
        ]);

        $this->team->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_user_can_export_backup() : void
    {
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Personal',
        ]);

        TwoFAccount::factory()->count(3)->create([
            'user_id'  => $this->user->id,
            'group_id' => $group->id,
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/export', [
                'include_groups' => true,
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'filename',
                'format',
                'size',
                'account_count',
                'group_count',
                'payload',
            ]);

        $data = $response->json();

        // C13: filename is user-scoped to avoid same-second collisions
        $this->assertStringStartsWith('2fa-vault-backup-u' . $this->user->id . '-', $data['filename']);
        $this->assertStringEndsWith('.vault', $data['filename']);
        $this->assertEquals(2, $data['format']);
        $this->assertEquals(3, $data['account_count']);
        $this->assertEquals(1, $data['group_count']);
        $this->assertTrue(Storage::disk('backups')->exists($data['filename']));

        // The JSON response carries the payload for client-side encryption
        $this->assertEquals(2, $data['payload']['format']);
        $this->assertEquals(3, $data['payload']['account_count']);
        $this->assertCount(3, $data['payload']['accounts']);
        $this->assertCount(1, $data['payload']['groups']);

        // Server-side copy is encrypted at rest — decrypt to verify content
        $encrypted  = Storage::disk('backups')->get($data['filename']);
        $decrypted  = Crypt::decryptString($encrypted);
        $backupData = json_decode($decrypted, true);

        $this->assertEquals(2, $backupData['format']);
        $this->assertEquals(3, $backupData['account_count']);

        $this->user->refresh();
        $this->assertNotNull($this->user->last_backup_at);
    }

    public function test_user_can_export_backup_via_legacy_alias_with_post() : void
    {
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Legacy Alias Group',
        ]);

        TwoFAccount::factory()->count(2)->create([
            'user_id'  => $this->user->id,
            'group_id' => $group->id,
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backup/export', [
                'include_groups' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'account_count' => 2,
                'group_count'   => 1,
            ]);

        $this->assertTrue(Storage::disk('backups')->exists($response->json('filename')));
    }

    public function test_user_can_export_backup_via_legacy_alias_with_get() : void
    {
        TwoFAccount::factory()->count(1)->create([
            'user_id' => $this->user->id,
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        // C5: the password is no longer part of the route contract — it used
        // to leak into query-string logs while being ignored server-side.
        $response = $this
            ->getJson('/api/v1/backup/export?include_groups=1');

        $response->assertOk()
            ->assertJson([
                'account_count' => 1,
            ]);

        $this->assertStringEndsWith('.vault', $response->json('filename'));
        $this->assertTrue(Storage::disk('backups')->exists($response->json('filename')));
    }

    public function test_export_requires_authentication() : void
    {
        $response = $this->postJson('/api/v1/backups/export', [
            'password' => 'strong-master-password',
        ]);

        $response->assertUnauthorized();
    }

    public function test_export_does_not_require_password() : void
    {
        // C1: the server-side password was theater — encryption now happens
        // client-side, so export works without a password field.
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/export', []);

        $response->assertOk()
            ->assertJsonStructure(['payload']);
    }

    public function test_export_with_no_accounts_creates_empty_backup() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/export', []);

        $response->assertOk();
        $this->assertEquals(0, $response->json('account_count'));
        $this->assertEquals(0, $response->json('group_count'));
    }

    public function test_user_can_import_vault_backup() : void
    {
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Imported Group',
        ]);

        $accounts = TwoFAccount::factory()->count(2)->create([
            'user_id'  => $this->user->id,
            'group_id' => $group->id,
        ]);

        $backupData = [
            'format'           => '2FA-Vault',
            'version'          => '2.0',
            'encrypted'        => true,
            'double_encrypted' => true,
            'account_count'    => 2,
            'groups'           => [
                [
                    'id'    => $group->id,
                    'name'  => $group->name,
                    'order' => $group->order_column,
                ],
            ],
            'accounts' => $accounts->map(fn (TwoFAccount $account) => [
                'id'        => $account->id,
                'service'   => $account->service,
                'account'   => $account->account,
                'secret'    => $account->secret,
                'encrypted' => false,
                'algorithm' => $account->algorithm,
                'digits'    => $account->digits,
                'period'    => $account->period,
                'otp_type'  => $account->otp_type,
                'group_id'  => $group->id,
            ])->all(),
        ];

        $file = $this->createUploadedBackupFile('test-backup.vault', $backupData);

        TwoFAccount::query()->delete();
        Group::where('user_id', $this->user->id)->delete();

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file'         => $file,
                'password'            => 'strong-master-password',
                'conflict_resolution' => 'skip',
                'import_groups'       => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'imported_count'      => 2,
                'skipped_count'       => 0,
                'failed_count'        => 0,
                'conflict_resolution' => 'skip',
            ]);

        $this->assertCount(2, TwoFAccount::where('user_id', $this->user->id)->get());
        $this->assertDatabaseHas('groups', [
            'user_id' => $this->user->id,
            'name'    => 'Imported Group',
        ]);
    }

    public function test_import_requires_authentication() : void
    {
        $file = UploadedFile::fake()->create('backup.vault', 100);

        $response = $this->postJson('/api/v1/backups/import', [
            'backup_file' => $file,
            'password'    => 'strong-master-password',
        ]);

        $response->assertUnauthorized();
    }

    public function test_import_requires_backup_file() : void
    {
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_file']);
    }

    public function test_import_without_password_works_for_vault_format() : void
    {
        // C1/RT4: the password is applied client-side (SPA decrypts v2 files
        // before upload) — the server never requires it.
        $file = $this->createUploadedBackupFile('test-backup.vault', [
            'format'   => '2FA-Vault',
            'version'  => '2.0',
            'accounts' => [],
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertOk();
    }

    public function test_import_rejects_undecrypted_v2_envelope() : void
    {
        // RT4: a format 2 envelope still carrying ciphertext must be refused
        // with a clear error, never silently imported via a legacy path.
        $file = $this->createUploadedBackupFile('encrypted.vault', [
            'app'         => '2FA-Vault',
            'format'      => 2,
            'version'     => '2.0',
            'datetime'    => now()->toIso8601String(),
            'encryption'  => [
                'algorithm'  => 'aes-256-gcm',
                'kdf'        => 'argon2id',
                'kdf_params' => ['time' => 3, 'memory_kib' => 65536, 'parallelism' => 1],
                'salt'       => base64_encode(random_bytes(32)),
                'iv'         => base64_encode(random_bytes(12)),
                'tag'        => base64_encode(random_bytes(16)),
            ],
            'data'        => base64_encode(random_bytes(128)),
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Decrypt it in the app first',
            $response->json('message')
        );
    }

    public function test_import_rejects_v2_envelope_with_stripped_format_header() : void
    {
        // RT4: stripping the format header must NOT downgrade the file to the
        // legacy path — the ciphertext data fails validation and the import
        // is refused.
        $file = $this->createUploadedBackupFile('stripped.vault', [
            'app'        => '2FA-Vault',
            'version'    => '2.0',
            'datetime'   => now()->toIso8601String(),
            'encryption' => ['algorithm' => 'aes-256-gcm', 'kdf' => 'argon2id'],
            'data'       => base64_encode(random_bytes(128)),
            'iv'         => null,
            'tag'        => null,
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_round_trip_export_then_import_restores_accounts() : void
    {
        TwoFAccount::factory()->count(2)->create([
            'user_id' => $this->user->id,
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');

        // Export: the SPA receives the payload, "decrypts" (here: uses it
        // directly as the decrypted payload) and uploads it back.
        $export = $this->postJson('/api/v1/backups/export')->assertOk()->json();
        $decryptedPayload = $export['payload'];

        TwoFAccount::query()->delete();

        $file = $this->createUploadedBackupFile('round-trip.vault', $decryptedPayload);
        $response = $this->postJson('/api/v1/backups/import', [
            'backup_file' => $file,
        ]);

        $response->assertOk()
            ->assertJson([
                'imported_count'       => 2,
                'failed_count'         => 0,
                'key_mismatch_warning' => false,
                'legacy_format_warning' => false,
            ]);

        $this->assertCount(2, TwoFAccount::where('user_id', $this->user->id)->get());
    }

    public function test_import_of_legacy_envelope_warns_about_legacy_format() : void
    {
        $backupService = app(\App\Services\BackupService::class);
        $legacyEnvelope = $backupService->buildLegacyEnvelope([
            'app'      => '2FA-Vault',
            'format'   => 2,
            'version'  => '2.0',
            'accounts' => [
                [
                    'service' => 'LegacyCo',
                    'account' => 'legacy@example.com',
                    'secret'  => 'JBSWY3DPEHPK3PXP',
                    'otp_type' => 'totp',
                ],
            ],
        ]);

        $file = $this->createUploadedBackupFile('legacy.vault', $legacyEnvelope);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'imported_count'        => 1,
                'legacy_format_warning' => true,
            ]);

        $this->assertDatabaseHas('twofaccounts', [
            'user_id' => $this->user->id,
            'service' => 'LegacyCo',
        ]);
    }

    public function test_import_reports_key_mismatch_for_encrypted_accounts() : void
    {
        // C3: user without E2EE imports encrypted accounts → warning + ids
        $this->user->encryption_version = 0;
        $this->user->save();

        $file = $this->createUploadedBackupFile('foreign.vault', [
            'format'             => 2,
            'version'            => '2.0',
            'encryption_version' => 1,
            'accounts'           => [
                [
                    'service'   => 'Foreign',
                    'account'   => 'foreign@example.com',
                    'secret'    => json_encode([
                        'ciphertext' => base64_encode('x'),
                        'iv'         => base64_encode('iv'),
                        'authTag'    => base64_encode('tag'),
                    ]),
                    'encrypted' => true,
                    'otp_type'  => 'totp',
                ],
            ],
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'imported_count'       => 1,
                'encrypted_count'      => 1,
                'key_mismatch_warning' => true,
            ]);

        $this->assertCount(1, $response->json('imported_account_ids'));
    }

    public function test_import_does_not_update_last_backup_at() : void
    {
        // C13: an import is not a backup
        $this->user->last_backup_at = null;
        $this->user->save();

        $file = $this->createUploadedBackupFile('test.vault', [
            'version'  => '2.0',
            'accounts' => [
                [
                    'service'  => 'S',
                    'account'  => 'a@example.com',
                    'secret'   => 'JBSWY3DPEHPK3PXP',
                    'otp_type' => 'totp',
                ],
            ],
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ])
            ->assertOk();

        $this->user->refresh();
        $this->assertNull($this->user->last_backup_at);
    }

    public function test_empty_json_is_rejected_as_invalid_format() : void
    {
        // C13: `[]` is valid JSON — it must fail format validation, not be
        // misreported as "not valid JSON".
        $file = UploadedFile::fake()->createWithContent('empty.json', '[]');

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertStatus(422);
    }

    public function test_invalid_backup_file_rejected() : void
    {
        $invalidFile = UploadedFile::fake()->createWithContent(
            'invalid-backup.vault',
            'This is not valid JSON'
        );

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $invalidFile,
                'password'    => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        $this->assertStringContainsString('Invalid backup file', $response->json('message'));
    }

    public function test_import_of_unsupported_version_is_rejected() : void
    {
        $file = $this->createUploadedBackupFile('unsupported.vault', [
            'format'   => '2FA-Vault',
            'version'  => '0.1',
            'accounts' => [],
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'password'    => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_import_2fa_vault_legacy_format_without_password() : void
    {
        $file = $this->createUploadedBackupFile('2FA-Vault-backup.json', [
            'app'      => '2FA-Vault',
            'version'  => '6.1.3',
            'accounts' => [
                [
                    'service'   => 'GitHub',
                    'account'   => 'user@example.com',
                    'secret'    => 'JBSWY3DPEHPK3PXP',
                    'algorithm' => 'sha1',
                    'digits'    => 6,
                    'period'    => 30,
                ],
            ],
        ]);

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'format'      => '2FA-Vault',
            ]);

        $response->assertOk()
            ->assertJson([
                'imported_count' => 1,
                'skipped_count'  => 0,
                'failed_count'   => 0,
            ]);

        $this->assertDatabaseHas('twofaccounts', [
            'user_id' => $this->user->id,
            'service' => 'GitHub',
            'account' => 'user@example.com',
        ]);
    }

    public function test_metadata_returns_backup_preview() : void
    {
        $file = UploadedFile::fake()->createWithContent(
            'preview.json',
            json_encode([
                'format'           => '2FA-Vault',
                'version'          => '2.0',
                'encrypted'        => true,
                'double_encrypted' => true,
                'exported_at'      => now()->toIso8601String(),
                'groups'           => [
                    ['id' => 1, 'name' => 'Personal', 'order' => 0],
                ],
                'accounts' => [
                    ['service' => 'GitHub', 'account' => 'user@example.com', 'encrypted' => true],
                ],
            ], JSON_PRETTY_PRINT)
        );

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->post('/api/v1/backups/metadata', [
                'backup_file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'format'                 => '2FA-Vault',
                'version'                => '2.0',
                'encrypted'              => true,
                'double_encrypted'       => true,
                'account_count'          => 1,
                'group_count'            => 1,
                'has_encrypted_accounts' => true,
                'compatible'             => true,
            ]);
    }

    public function test_metadata_rejects_invalid_file_type() : void
    {
        $invalidFile = UploadedFile::fake()->create('backup.txt', 10, 'text/plain');

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->post('/api/v1/backups/metadata', [
                'backup_file' => $invalidFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_file']);
    }

    public function test_info_returns_backup_stats() : void
    {
        Group::factory()->create([
            'user_id' => $this->user->id,
        ]);

        TwoFAccount::factory()->create([
            'user_id'   => $this->user->id,
            'encrypted' => true,
        ]);

        TwoFAccount::factory()->create([
            'user_id'   => $this->user->id,
            'encrypted' => false,
        ]);

        $this->user->last_backup_at = now()->subDays(5);
        $this->user->save();

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson('/api/v1/backups/info');

        $response->assertOk()
            ->assertJson([
                'total_accounts'       => 2,
                'encrypted_accounts'   => 1,
                'unencrypted_accounts' => 1,
                'total_groups'         => 1,
                'has_backup'           => true,
                'should_backup'        => false,
            ]);

        $this->assertNotNull($response->json('estimated_size_bytes'));
        $this->assertNotNull($response->json('estimated_size_human'));
        $this->assertNotNull($response->json('last_backup_at'));
    }

    private function createUploadedBackupFile(string $filename, array $backupData) : UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            json_encode($backupData, JSON_PRETTY_PRINT)
        );
    }
}
