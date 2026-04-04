<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Team;
use App\Models\TwoFAccount;
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
        
        // Create user with team
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
            'encryption_enabled' => true,
        ]);
        
        $this->team = Team::factory()->create([
            'name' => 'Test Team',
            'owner_id' => $this->user->id,
        ]);
        
        $this->team->users()->attach($this->user->id, ['role' => 'owner']);
    }

    /** @test */
    public function test_user_can_export_backup()
    {
        // Create some test accounts
        TwoFAccount::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/export', [
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'filename',
                'size',
                'accounts_count',
            ]);

        $data = $response->json();
        
        // Verify filename format
        $this->assertStringStartsWith('2fa-vault-backup-', $data['filename']);
        $this->assertStringEndsWith('.vault', $data['filename']);
        
        // Verify accounts count
        $this->assertEquals(3, $data['accounts_count']);
        
        // Verify backup file was created
        $this->assertTrue(Storage::exists('backups/' . $data['filename']));
        
        // Verify backup content structure (should be encrypted JSON)
        $backupContent = Storage::get('backups/' . $data['filename']);
        $backupData = json_decode($backupContent, true);
        
        $this->assertArrayHasKey('app', $backupData);
        $this->assertEquals('2FA-Vault', $backupData['app']);
        
        $this->assertArrayHasKey('version', $backupData);
        $this->assertArrayHasKey('datetime', $backupData);
        $this->assertArrayHasKey('encryption', $backupData);
        $this->assertArrayHasKey('data', $backupData);
        $this->assertArrayHasKey('iv', $backupData);
        $this->assertArrayHasKey('tag', $backupData);
        
        // Verify encryption metadata
        $this->assertEquals('aes-256-gcm', $backupData['encryption']['algorithm']);
        $this->assertEquals('argon2id', $backupData['encryption']['kdf']);
    }

    /** @test */
    public function test_export_requires_authentication()
    {
        $response = $this->postJson('/api/v1/backups/export', [
            'password' => 'strong-master-password',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_export_requires_password()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/export', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /** @test */
    public function test_user_can_import_backup()
    {
        // First, create and export a backup
        TwoFAccount::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
        ]);

        $exportResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/export', [
                'password' => 'strong-master-password',
            ]);

        $backupFilename = $exportResponse->json('filename');
        $backupContent = Storage::get('backups/' . $backupFilename);
        
        // Delete accounts to prepare for import
        TwoFAccount::query()->delete();
        
        // Create uploaded file from backup content
        $file = UploadedFile::fake()->createWithContent(
            $backupFilename,
            $backupContent
        );

        // Import the backup
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'imported_count',
                'skipped_count',
                'errors',
            ]);

        $data = $response->json();
        
        // Verify import count
        $this->assertEquals(2, $data['imported_count']);
        $this->assertEquals(0, $data['skipped_count']);
        $this->assertEmpty($data['errors']);
        
        // Verify accounts were restored
        $this->assertCount(2, TwoFAccount::all());
    }

    /** @test */
    public function test_import_requires_authentication()
    {
        $file = UploadedFile::fake()->create('backup.vault', 100);

        $response = $this->postJson('/api/v1/backups/import', [
            'backup_file' => $file,
            'password' => 'strong-master-password',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_import_requires_backup_file()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/import', [
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_file']);
    }

    /** @test */
    public function test_import_requires_password()
    {
        $file = UploadedFile::fake()->create('backup.vault', 100);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    /** @test */
    public function test_invalid_backup_file_rejected()
    {
        // Create invalid JSON file
        $invalidFile = UploadedFile::fake()->createWithContent(
            'invalid-backup.vault',
            'This is not valid JSON'
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $invalidFile,
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
        
        $this->assertStringContainsString('Invalid backup file', $response->json('message'));
    }

    /** @test */
    public function test_backup_with_wrong_password_rejected()
    {
        // Create and export backup with password
        TwoFAccount::factory()->create([
            'user_id' => $this->user->id,
            'team_id' => $this->team->id,
        ]);

        $exportResponse = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/export', [
                'password' => 'correct-password',
            ]);

        $backupFilename = $exportResponse->json('filename');
        $backupContent = Storage::get('backups/' . $backupFilename);
        
        $file = UploadedFile::fake()->createWithContent(
            $backupFilename,
            $backupContent
        );

        // Try to import with wrong password
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'errors']);
        
        $this->assertStringContainsString('password', strtolower($response->json('message')));
    }

    /** @test */
    public function test_backup_file_extension_validation()
    {
        // Try to upload non-.vault file
        $invalidFile = UploadedFile::fake()->create('backup.txt', 100);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $invalidFile,
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['backup_file']);
    }

    /** @test */
    public function test_import_2fauth_legacy_format()
    {
        // Create 2FAuth-style JSON backup (unencrypted)
        $legacyBackup = [
            'app' => '2FAuth',
            'version' => '6.1.3',
            'datetime' => now()->toIso8601String(),
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
        ];

        $file = UploadedFile::fake()->createWithContent(
            '2fauth-backup.json',
            json_encode($legacyBackup)
        );

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/import', [
                'backup_file' => $file,
                'format' => '2fauth',
            ]);

        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertEquals(1, $data['imported_count']);
        
        // Verify account was imported and encrypted
        $account = TwoFAccount::first();
        $this->assertEquals('GitHub', $account->service);
        $this->assertEquals('user@example.com', $account->account);
    }

    /** @test */
    public function test_export_with_no_accounts_creates_empty_backup()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/backups/export', [
                'password' => 'strong-master-password',
            ]);

        $response->assertStatus(200);
        
        $data = $response->json();
        $this->assertEquals(0, $data['accounts_count']);
    }
}
