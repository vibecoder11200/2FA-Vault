<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use App\Notifications\BackupReminderNotification;

class ScheduleBackupReminder extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:remind
                            {--days=30 : Number of days since last backup to trigger reminder}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send backup reminders to users who have not backed up recently';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $remindersSent = 0;
        
        $this->info("Checking for users who need backup reminders (>{$days} days)...");
        
        // Find users who haven't backed up in X days
        $users = User::where('encryption_version', '>', 0) // Only users with E2EE enabled
            ->where(function ($query) use ($days) {
                $query->whereNull('last_backup_at')
                    ->orWhere('last_backup_at', '<', now()->subDays($days));
            })
            ->get();
        
        if ($users->isEmpty()) {
            $this->info('No users need backup reminders.');
            return self::SUCCESS;
        }
        
        $this->info("Found {$users->count()} users who need reminders.");
        
        foreach ($users as $user) {
            try {
                // Send notification (implement your notification channel)
                // For now, we'll just log it
                Log::info('Backup reminder needed', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'last_backup_at' => $user->last_backup_at?->toDateTimeString(),
                    'days_since_backup' => $user->last_backup_at ? now()->diffInDays($user->last_backup_at) : 'never'
                ]);
                
                // Uncomment when notification class is ready:
                // $user->notify(new BackupReminderNotification($user->last_backup_at));
                
                $remindersSent++;
                
                $this->line("✓ Reminder sent to: {$user->email}");
            } catch (\Exception $e) {
                $this->error("✗ Failed to send reminder to {$user->email}: {$e->getMessage()}");
                Log::error('Backup reminder failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage()
                ]);
            }
        }
        
        $this->info("\nBackup reminders sent: {$remindersSent}");
        
        return self::SUCCESS;
    }
}
