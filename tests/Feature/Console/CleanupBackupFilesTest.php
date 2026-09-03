<?php

namespace Tests\Feature\Console;

use App\Console\Commands\CleanupBackupFiles;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * CleanupBackupFilesTest test class
 */
#[CoversClass(CleanupBackupFiles::class)]
class CleanupBackupFilesTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
    }

    #[Test]
    public function test_cleanup_deletes_files_older_than_specified_hours(): void
    {
        $disk = Storage::disk('backups');

        // Create a file and set its modification time to 3 hours ago
        $disk->put('old-backup.vault', 'old backup content');
        $oldPath = $disk->path('old-backup.vault');
        touch($oldPath, now()->subHours(3)->timestamp);

        // Create a recent file that should NOT be deleted
        $disk->put('recent-backup.vault', 'recent backup content');

        $this->artisan('backup:cleanup', ['--hours' => 1])
            ->expectsOutput('Deleted 1 stale backup file(s).')
            ->assertSuccessful();

        $this->assertFalse($disk->exists('old-backup.vault'));
        $this->assertTrue($disk->exists('recent-backup.vault'));
    }

    #[Test]
    public function test_cleanup_preserves_recent_files(): void
    {
        $disk = Storage::disk('backups');

        // Create recent files
        $disk->put('backup-1.vault', 'content 1');
        $disk->put('backup-2.vault', 'content 2');

        $this->artisan('backup:cleanup', ['--hours' => 1])
            ->expectsOutput('Deleted 0 stale backup file(s).')
            ->assertSuccessful();

        $this->assertTrue($disk->exists('backup-1.vault'));
        $this->assertTrue($disk->exists('backup-2.vault'));
    }

    #[Test]
    public function test_cleanup_handles_empty_directory(): void
    {
        $this->artisan('backup:cleanup', ['--hours' => 1])
            ->expectsOutput('Deleted 0 stale backup file(s).')
            ->assertSuccessful();
    }

    #[Test]
    public function test_cleanup_reports_deleted_count(): void
    {
        $disk = Storage::disk('backups');

        // Create multiple old files
        for ($i = 1; $i <= 3; $i++) {
            $filename = "old-backup-{$i}.vault";
            $disk->put($filename, "content {$i}");
            touch($disk->path($filename), now()->subHours(2)->timestamp);
        }

        $this->artisan('backup:cleanup', ['--hours' => 1])
            ->expectsOutput('Deleted 3 stale backup file(s).')
            ->assertSuccessful();

        // All old files should be gone
        $this->assertCount(0, $disk->files());
    }

    #[Test]
    public function test_cleanup_uses_default_one_hour(): void
    {
        $disk = Storage::disk('backups');

        // Create file modified 30 min ago — should be kept
        $disk->put('half-hour.vault', 'content');
        touch($disk->path('half-hour.vault'), now()->subMinutes(30)->timestamp);

        // Create file modified 2 hours ago — should be deleted
        $disk->put('two-hour.vault', 'content');
        touch($disk->path('two-hour.vault'), now()->subHours(2)->timestamp);

        // Run without --hours option (defaults to 1)
        $this->artisan('backup:cleanup')
            ->expectsOutput('Deleted 1 stale backup file(s).')
            ->assertSuccessful();

        $this->assertTrue($disk->exists('half-hour.vault'));
        $this->assertFalse($disk->exists('two-hour.vault'));
    }

    #[Test]
    public function test_cleanup_with_custom_hours_threshold(): void
    {
        $disk = Storage::disk('backups');

        // File 5 hours old — should be deleted with --hours=4
        $disk->put('five-hour.vault', 'content');
        touch($disk->path('five-hour.vault'), now()->subHours(5)->timestamp);

        // File 3 hours old — should be kept with --hours=4
        $disk->put('three-hour.vault', 'content');
        touch($disk->path('three-hour.vault'), now()->subHours(3)->timestamp);

        $this->artisan('backup:cleanup', ['--hours' => 4])
            ->expectsOutput('Deleted 1 stale backup file(s).')
            ->assertSuccessful();

        $this->assertFalse($disk->exists('five-hour.vault'));
        $this->assertTrue($disk->exists('three-hour.vault'));
    }

    /**
     * C4: --hours=0 must clamp to 1 hour instead of wiping ALL backups.
     */
    #[Test]
    public function test_cleanup_with_zero_hours_clamps_to_one_hour(): void
    {
        $disk = Storage::disk('backups');

        $disk->put('half-hour.vault', 'content');
        touch($disk->path('half-hour.vault'), now()->subMinutes(30)->timestamp);
        $disk->put('two-hour.vault', 'content');
        touch($disk->path('two-hour.vault'), now()->subHours(2)->timestamp);

        $this->artisan('backup:cleanup', ['--hours' => 0])
            ->expectsOutput('Deleted 1 stale backup file(s).')
            ->assertSuccessful();

        $this->assertTrue($disk->exists('half-hour.vault'));
        $this->assertFalse($disk->exists('two-hour.vault'));
    }

    #[Test]
    public function test_cleanup_with_negative_hours_clamps_to_one_hour(): void
    {
        $disk = Storage::disk('backups');

        $disk->put('half-hour.vault', 'content');
        touch($disk->path('half-hour.vault'), now()->subMinutes(30)->timestamp);

        $this->artisan('backup:cleanup', ['--hours' => -5])
            ->expectsOutput('Deleted 0 stale backup file(s).')
            ->assertSuccessful();

        // A negative cutoff would otherwise delete every file immediately.
        $this->assertTrue($disk->exists('half-hour.vault'));
    }

    #[Test]
    public function test_cleanup_with_garbage_hours_clamps_to_one_hour(): void
    {
        $disk = Storage::disk('backups');

        $disk->put('half-hour.vault', 'content');
        touch($disk->path('half-hour.vault'), now()->subMinutes(30)->timestamp);
        $disk->put('three-hour.vault', 'content');
        touch($disk->path('three-hour.vault'), now()->subHours(3)->timestamp);

        // "(int) 'garbage'" === 0 → clamped to 1 hour, not a full wipe.
        $this->artisan('backup:cleanup', ['--hours' => 'garbage'])
            ->expectsOutput('Deleted 1 stale backup file(s).')
            ->assertSuccessful();

        $this->assertTrue($disk->exists('half-hour.vault'));
        $this->assertFalse($disk->exists('three-hour.vault'));
    }
}
