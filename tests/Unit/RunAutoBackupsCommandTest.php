<?php

namespace Tests\Unit;

use App\Console\Commands\RunAutoBackupsCommand;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * C12: due-ness catch-up semantics — the docblock of RunAutoBackupsCommand
 * claims this unit coverage; a backup is due once the scheduled time-of-day
 * has been reached and the last run predates the current frequency window,
 * so a missed scheduler tick no longer skips the whole day/week/month.
 */
#[CoversClass(RunAutoBackupsCommand::class)]
class RunAutoBackupsCommandTest extends TestCase
{
    private function isDue(array $preferences, string $now, ?string $lastRun): bool
    {
        return RunAutoBackupsCommand::isBackupDue(
            $preferences,
            Carbon::parse($now)->utc(),
            $lastRun === null ? null : Carbon::parse($lastRun)->utc()
        );
    }

    #[Test]
    public function test_due_when_never_run_and_time_reached(): void
    {
        $this->assertTrue($this->isDue(
            ['auto_backup_frequency' => 'daily', 'auto_backup_time' => '02:00'],
            '2026-09-02 03:00:00',
            null
        ));
    }

    #[Test]
    public function test_not_due_before_scheduled_time(): void
    {
        $this->assertFalse($this->isDue(
            ['auto_backup_frequency' => 'daily', 'auto_backup_time' => '02:00'],
            '2026-09-02 01:59:00',
            '2026-09-01 02:00:00'
        ));
    }

    #[Test]
    public function test_daily_catches_up_after_missed_exact_minute(): void
    {
        // Last run yesterday; today's 02:00 tick was missed and it is now
        // 02:37 — still due (no exact-minute equality requirement).
        $this->assertTrue($this->isDue(
            ['auto_backup_frequency' => 'daily', 'auto_backup_time' => '02:00'],
            '2026-09-02 02:37:00',
            '2026-09-01 02:00:00'
        ));
    }

    #[Test]
    public function test_daily_not_due_again_after_same_day_run(): void
    {
        $this->assertFalse($this->isDue(
            ['auto_backup_frequency' => 'daily', 'auto_backup_time' => '02:00'],
            '2026-09-02 22:00:00',
            '2026-09-02 02:05:00'
        ));
    }

    #[Test]
    public function test_weekly_catches_up_when_window_elapsed(): void
    {
        // Last run 8 days ago and today's scheduled time passed — due even
        // though the exact scheduled minute was missed.
        $this->assertTrue($this->isDue(
            ['auto_backup_frequency' => 'weekly', 'auto_backup_time' => '03:00'],
            '2026-09-02 03:15:00',
            '2026-08-25 03:00:00'
        ));
    }

    #[Test]
    public function test_weekly_not_due_within_window(): void
    {
        $this->assertFalse($this->isDue(
            ['auto_backup_frequency' => 'weekly', 'auto_backup_time' => '03:00'],
            '2026-09-02 03:15:00',
            '2026-08-30 03:00:00'
        ));
    }

    #[Test]
    public function test_monthly_catches_up_when_window_elapsed(): void
    {
        $this->assertTrue($this->isDue(
            ['auto_backup_frequency' => 'monthly', 'auto_backup_time' => '04:00'],
            '2026-09-02 04:30:00',
            '2026-07-15 04:00:00'
        ));
    }

    #[Test]
    public function test_monthly_not_due_within_window(): void
    {
        $this->assertFalse($this->isDue(
            ['auto_backup_frequency' => 'monthly', 'auto_backup_time' => '04:00'],
            '2026-09-02 04:30:00',
            '2026-08-20 04:00:00'
        ));
    }

    #[Test]
    public function test_unknown_frequency_is_never_due_after_a_prior_run(): void
    {
        // A never-run user is always due once the time-of-day passes
        // (conservative default); an unknown frequency is never due again.
        $this->assertFalse($this->isDue(
            ['auto_backup_frequency' => 'hourly', 'auto_backup_time' => '02:00'],
            '2026-09-02 03:00:00',
            '2026-09-02 02:00:00'
        ));
    }

    #[Test]
    public function test_defaults_to_daily_at_two(): void
    {
        $this->assertTrue($this->isDue([], '2026-09-02 02:00:00', null));
        $this->assertFalse($this->isDue([], '2026-09-02 01:59:59', null));
    }
}
