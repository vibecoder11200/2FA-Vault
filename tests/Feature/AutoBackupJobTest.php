<?php

namespace Tests\Feature;

use App\Jobs\AutoBackupJob;
use App\Mail\AutoBackupNotificationMail;
use App\Models\User;
use App\Services\BackupDestinationService;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * AutoBackupJobTest test class
 */
#[CoversClass(AutoBackupJob::class)]
class AutoBackupJobTest extends FeatureTestCase
{
    use RefreshDatabase;

    private function seedDestination(User $user, array $overrides = []): int
    {
        unset($overrides['user_id'], $overrides['created_at'], $overrides['updated_at']);

        return DB::table('user_backup_destinations')->insertGetId([
            'user_id' => $user->id,
            'label' => $overrides['label'] ?? 'local',
            'type' => $overrides['type'] ?? 'local',
            'config' => json_encode($overrides['config'] ?? ['path' => 'backups']),
            'is_active' => $overrides['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function test_job_sends_backup_to_all_active_destinations_and_notifies()
    {
        Mail::fake();

        $user = User::factory()->create();
        $this->seedDestination($user, ['label' => 'first']);
        $this->seedDestination($user, ['label' => 'second']);
        // Inactive destination must be skipped.
        $this->seedDestination($user, ['label' => 'inactive', 'is_active' => false]);

        $this->mock(BackupService::class, function (MockInterface $backup) {
            $backup->shouldReceive('generateEncryptedBackup')
                ->once()
                ->andReturn(['schema' => 'test', 'data' => []]);
            // Passwordless destinations get the legacy envelope.
            $backup->shouldReceive('buildLegacyEnvelope')
                ->twice()
                ->andReturn(['app' => '2FA-Vault', 'data' => 'legacy']);
            $backup->shouldReceive('buildEncryptedEnvelope')->never();
        });

        $send = $this->mock(BackupDestinationService::class, function (MockInterface $dest) {
            // Two active destinations → send() called exactly twice.
            $dest->shouldReceive('send')->twice();
        });

        dispatch(new AutoBackupJob($user));

        Mail::assertSent(AutoBackupNotificationMail::class, fn ($m) => $m->hasTo($user->email));

        // last_auto_backup_at preference recorded
        $user->refresh();
        $this->assertNotNull($user->preferences->get('last_auto_backup_at'));
    }

    #[Test]
    public function test_job_continues_after_one_destination_fails()
    {
        Mail::fake();

        $user = User::factory()->create();
        $first = $this->seedDestination($user, ['label' => 'will-fail']);
        $second = $this->seedDestination($user, ['label' => 'will-succeed']);

        $this->mock(BackupService::class, function (MockInterface $backup) {
            $backup->shouldReceive('generateEncryptedBackup')->andReturn(['data' => []]);
            $backup->shouldReceive('buildLegacyEnvelope')
                ->twice()
                ->andReturn(['app' => '2FA-Vault', 'data' => 'legacy']);
        });
        $this->mock(BackupDestinationService::class, function (MockInterface $dest) use ($first, $second) {
            $dest->shouldReceive('send')
                ->withArgs(function ($destination) use ($first) {
                    return $destination->id === $first;
                })
                ->andThrow(new \RuntimeException('S3 down'));
            $dest->shouldReceive('send')
                ->withArgs(function ($destination) use ($second) {
                    return $destination->id === $second;
                })
                ->andReturnNull();
        });

        dispatch(new AutoBackupJob($user));

        // Failed destination is marked failed; succeeded one is marked success.
        $this->assertSame('failed', DB::table('user_backup_destinations')->where('id', $first)->value('last_run_status'));
        $this->assertSame('success', DB::table('user_backup_destinations')->where('id', $second)->value('last_run_status'));

        // Notification mail still sent, and includes the failed destination label.
        Mail::assertSent(AutoBackupNotificationMail::class, function ($mail) {
            return in_array('will-fail', $mail->failedDestinations);
        });
    }

    #[Test]
    public function test_job_records_last_auto_backup_at_after_run()
    {
        Mail::fake();

        $user = User::factory()->create();

        $this->mock(BackupService::class, function (MockInterface $backup) {
            $backup->shouldReceive('generateEncryptedBackup')->andReturn(['data' => []]);
            $backup->shouldReceive('buildLegacyEnvelope')->andReturn(['app' => '2FA-Vault', 'data' => 'legacy']);
        });
        $this->mock(BackupDestinationService::class, function (MockInterface $dest) {
            $dest->shouldReceive('send');
        });

        $before = now()->subSecond()->toIso8601String();

        dispatch(new AutoBackupJob($user));

        $user->refresh();
        $this->assertNotNull($user->preferences->get('last_auto_backup_at'));
        $this->assertGreaterThan($before, $user->preferences->get('last_auto_backup_at'));
    }

    /**
     * C1: email destinations never receive attachments unless they
     * explicitly opt in (email_attachments, default false).
     */
    #[Test]
    public function test_email_destination_without_opt_in_is_skipped()
    {
        Mail::fake();

        $user = User::factory()->create();
        $destination = $this->seedDestination($user, [
            'label' => 'email-no-optin',
            'type'  => 'email',
            'config' => ['email' => 'somebody@example.com'],
        ]);

        $this->mock(BackupService::class, function (MockInterface $backup) {
            $backup->shouldReceive('generateEncryptedBackup')->andReturn(['accounts' => []]);
            $backup->shouldReceive('buildEncryptedEnvelope')->never();
            $backup->shouldReceive('buildLegacyEnvelope')->never();
        });
        $this->mock(BackupDestinationService::class, function (MockInterface $dest) {
            $dest->shouldReceive('send')->never();
        });

        dispatch(new AutoBackupJob($user));

        $this->assertSame(
            'skipped',
            DB::table('user_backup_destinations')->where('id', $destination)->value('last_run_status')
        );
    }

    /**
     * C1: opting in is not enough — an email attachment must be an encrypted
     * envelope, so a missing encryption password fails the destination.
     */
    #[Test]
    public function test_email_destination_with_opt_in_but_no_password_fails()
    {
        Mail::fake();

        $user = User::factory()->create();
        $destination = $this->seedDestination($user, [
            'label'  => 'email-optin-plaintext',
            'type'   => 'email',
            'config' => ['email' => 'somebody@example.com', 'email_attachments' => true],
        ]);

        $this->mock(BackupService::class, function (MockInterface $backup) {
            $backup->shouldReceive('generateEncryptedBackup')->andReturn(['accounts' => []]);
            $backup->shouldReceive('buildEncryptedEnvelope')->never();
            $backup->shouldReceive('buildLegacyEnvelope')->never();
        });
        $this->mock(BackupDestinationService::class, function (MockInterface $dest) {
            $dest->shouldReceive('send')->never();
        });

        dispatch(new AutoBackupJob($user));

        $this->assertSame(
            'failed',
            DB::table('user_backup_destinations')->where('id', $destination)->value('last_run_status')
        );
    }

    /**
     * C1: destinations with an encryption_password receive a real format 2
     * envelope; passwordless destinations keep the legacy envelope.
     */
    #[Test]
    public function test_destination_with_password_gets_encrypted_envelope()
    {
        Mail::fake();

        $user = User::factory()->create();
        $encrypted = $this->seedDestination($user, [
            'label'  => 's3-encrypted',
            'type'   => 'local',
            'config' => ['path' => 'backups', 'encryption_password' => 's3cret-pass'],
        ]);
        $legacy = $this->seedDestination($user, [
            'label'  => 's3-legacy',
            'type'   => 'local',
            'config' => ['path' => 'backups'],
        ]);

        $this->mock(BackupService::class, function (MockInterface $backup) {
            $backup->shouldReceive('generateEncryptedBackup')->andReturn(['accounts' => []]);
            $backup->shouldReceive('buildEncryptedEnvelope')
                ->once()
                ->withArgs(function ($payload, $password) {
                    return $password === 's3cret-pass';
                })
                ->andReturn(['app' => '2FA-Vault', 'format' => 2, 'data' => 'enc']);
            $backup->shouldReceive('buildLegacyEnvelope')
                ->once()
                ->andReturn(['app' => '2FA-Vault', 'data' => 'legacy']);
        });
        $this->mock(BackupDestinationService::class, function (MockInterface $dest) {
            $dest->shouldReceive('send')->twice();
        });

        dispatch(new AutoBackupJob($user));

        $this->assertSame('success', DB::table('user_backup_destinations')->where('id', $encrypted)->value('last_run_status'));
        $this->assertSame('success', DB::table('user_backup_destinations')->where('id', $legacy)->value('last_run_status'));
    }
}
