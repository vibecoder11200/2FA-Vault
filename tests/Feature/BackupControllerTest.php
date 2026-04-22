<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Team;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'encryption_enabled' => true,
            'encryption_salt' => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version' => 1,
            'vault_locked' => false,
        ]);

        $this->team = Team::factory()->create([
            'name' => 'Test Team',
            'owner_id' => $this->user->id,
        ]);

        $this->team->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_user_can_export_backup(): void
    {
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Personal',
        ]);

        TwoFAccount::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'group_id' => $group->id,
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/export', [
                'password' => 'strong-master-password',
                'include_groups' => true,
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'filename',
                'size',
                'account_count',
                'group_count',
            ]);

        $data = $response->json();

        $this->assertStringStartsWith('2fa-vault-backup-', $data['filename']);
        $this->assertStringEndsWith('.vault', $data['filename']);
        $this->assertEquals(3, $data['account_count']);
        $this->assertEquals(1, $data['group_count']);
        $this->assertTrue(Storage::exists('backups/' . $data['filename']));

        $backupData = json_decode(Storage::get('backups/' . $data['filename']), true);

        $this->assertEquals('2FA-Vault', $backupData['app']);
        $this->assertArrayHasKey('encryption', $backupData);
        $this->assertArrayHasKey('data', $backupData);

        $decoded = json_decode(base64_decode($backupData['data']), true);
        $this->assertEquals(3, $decoded['account_count']);
        $this->assertCount(1, $decoded['groups']);

        $this->user->refresh();
        $this->assertNotNull($this->user->last_backup_at);
    }

    public function test_user_can_export_backup_via_legacy_alias_with_post(): void
    {
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Legacy Alias Group',
        ]);

        TwoFAccount::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'group_id' => $group->id,
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backup/export', [
                'password' => 'strong-master-password',
                'include_groups' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'account_count' => 2,
                'group_count' => 1,
            ]);

        $this->assertTrue(Storage::exists('backups/' . $response->json('filename')));
    }

    public function test_user_can_export_backup_via_legacy_alias_with_get(): void
    {
        TwoFAccount::factory()->count(1)->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->getJson('/api/v1/backup/export?password=strong-master-password&include_groups=1');

        $response->assertOk()
            ->assertJson([
                'account_count' => 1,
            ]);

        $this->assertStringEndsWith('.vault', $response->json('filename'));
        $this->assertTrue(Storage::exists('backups/' . $response->json('filename')));
    }

    public function test_export_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/backups/export', [
            'password' => 'strong-master-password',
        ]);

        $response->assertUnauthorized();
    }

    public function test_export_requires_password(): void
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/export', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_export_with_no_accounts_creates_empty_backup(): void
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/export', [
                'password' => 'strong-master-password',
            ]);

        $response->assertOk();
        $this->assertEquals(0, $response->json('account_count'));
        $this->assertEquals(0, $response->json('group_count'));
    }

    public function test_user_can_import_vault_backup(): void
    {
        $group = Group::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Imported Group',
        ]);

        $accounts = TwoFAccount::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'group_id' => $group->id,
        ]);

        $backupData = [
            'format' => '2FA-Vault',
            'version' => '2.0',
            'encrypted' => true,
            'double_encrypted' => true,
            'account_count' => 2,
            'groups' => [
                [
                    'id' => $group->id,
                    'name' => $group->name,
                    'order' => $group->order_column,
                ],
            ],
            'accounts' => $accounts->map(fn (TwoFAccount $account) => [
                'id' => $account->id,
                'service' => $account->service,
                'account' => $account->account,
                'secret' => $account->secret,
                'encrypted' => false,
                'algorithm' => $account->algorithm,
                'digits' => $account->digits,
                'period' => $account->period,
                'otp_type' => $account->otp_type,
                'group_id' => $group->id,
            ])->all(),
        ];

        $file = $this->createUploadedBackupFile('test-backup.vault', $backupData);

        TwoFAccount::query()->delete();
        Group::where('user_id', $this->user->id)->delete();

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'password' => 'strong-master-password',
                'conflict_resolution' => 'skip',
                'import_groups' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'imported_count' => 2,
                'skipped_count' => 0,
                'failed_count' => 0,
                'conflict_resolution' => 'skip',
            ]);

        $this->assertCount(2, TwoFAccount::where('user_id', $this->user->id)->get());
        $this->assertDatabaseHas('groups', [
            'user_id' => $this->user->id,
            'name' => 'Imported Group',
        ]);
    }

    public function test_import_requires_authentication(): void
    {
        $file = UploadedFile::fake()->create('backup.vault', 100);

        $response = $this->postJson('/api/v1/backups/import', [
            'backup_file' => $file,
            'password' => 'strong-master-password',
        ]);

        $response->assertUnauthorized();
    }

    public function test_import_requires_backup_file(): void
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/import', [
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_file']);
    }

    public function test_import_requires_password_for_vault_format(): void
    {
        $file = $this->createUploadedBackupFile('test-backup.vault', [
            'format' => '2FA-Vault',
            'version' => '2.0',
            'accounts' => [],
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_invalid_backup_file_rejected(): void
    {
        $invalidFile = UploadedFile::fake()->createWithContent(
            'invalid-backup.vault',
            'This is not valid JSON'
        );

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $invalidFile,
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);

        $this->assertStringContainsString('Invalid backup file', $response->json('message'));
    }

    public function test_import_of_unsupported_version_is_rejected(): void
    {
        $file = $this->createUploadedBackupFile('unsupported.vault', [
            'format' => '2FA-Vault',
            'version' => '0.1',
            'accounts' => [],
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_import_2fauth_legacy_format_without_password(): void
    {
        $file = $this->createUploadedBackupFile('2fauth-backup.json', [
            'app' => '2FAuth',
            'version' => '6.1.3',
            'accounts' => [
                [
                    'service' => 'GitHub',
                    'account' => 'user@example.com',
                    'secret' => 'JBSWY3DPEHPK3PXP',
                    'algorithm' => 'sha1',
                    'digits' => 6,
                    'period' => 30,
                ],
            ],
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'format' => '2fauth',
            ]);

        $response->assertOk()
            ->assertJson([
                'imported_count' => 1,
                'skipped_count' => 0,
                'failed_count' => 0,
            ]);

        $this->assertDatabaseHas('twofaccounts', [
            'user_id' => $this->user->id,
            'service' => 'GitHub',
            'account' => 'user@example.com',
        ]);
    }

    public function test_metadata_returns_backup_preview(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'preview.json',
            json_encode([
                'format' => '2FA-Vault',
                'version' => '2.0',
                'encrypted' => true,
                'double_encrypted' => true,
                'exported_at' => now()->toIso8601String(),
                'groups' => [
                    ['id' => 1, 'name' => 'Personal', 'order' => 0],
                ],
                'accounts' => [
                    ['service' => 'GitHub', 'account' => 'user@example.com', 'encrypted' => true],
                ],
            ], JSON_PRETTY_PRINT)
        );

        $response = $this->actingAs($this->user, 'api-guard')
            ->post('/api/v1/backups/metadata', [
                'backup_file' => $file,
            ]);

        $response->assertOk()
            ->assertJson([
                'format' => '2FA-Vault',
                'version' => '2.0',
                'encrypted' => true,
                'double_encrypted' => true,
                'account_count' => 1,
                'group_count' => 1,
                'has_encrypted_accounts' => true,
                'compatible' => true,
            ]);
    }

    public function test_metadata_rejects_invalid_file_type(): void
    {
        $invalidFile = UploadedFile::fake()->create('backup.txt', 10, 'text/plain');

        $response = $this->actingAs($this->user, 'api-guard')
            ->post('/api/v1/backups/metadata', [
                'backup_file' => $invalidFile,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_file']);
    }

    public function test_info_returns_backup_stats(): void
    {
        Group::factory()->create([
            'user_id' => $this->user->id,
        ]);

        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => true,
        ]);

        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'encrypted' => false,
        ]);

        $this->user->last_backup_at = now()->subDays(5);
        $this->user->save();

        $response = $this->actingAs($this->user, 'api-guard')
            ->getJson('/api/v1/backups/info');

        $response->assertOk()
            ->assertJson([
                'total_accounts' => 2,
                'encrypted_accounts' => 1,
                'unencrypted_accounts' => 1,
                'total_groups' => 1,
                'has_backup' => true,
                'should_backup' => false,
            ]);

        $this->assertNotNull($response->json('estimated_size_bytes'));
        $this->assertNotNull($response->json('estimated_size_human'));
        $this->assertNotNull($response->json('last_backup_at'));
    }

    private function createUploadedBackupFile(string $filename, array $backupData): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $filename,
            json_encode($backupData, JSON_PRETTY_PRINT)
        );
    }
}

