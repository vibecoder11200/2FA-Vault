<?php

namespace App\Jobs;

use App\Mail\AutoBackupNotificationMail;
use App\Models\User;
use App\Services\BackupDestinationService;
use App\Services\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Generates a .vault backup for a user and pushes it to all of their
 * active backup destinations, then sends a summary notification.
 *
 * C1 (F3): destinations with a configured `encryption_password` receive a
 * format 2 envelope (AES-256-GCM, Argon2id-derived key) — plaintext account
 * data never leaves the server unencrypted. Destinations without a password
 * keep the legacy envelope (the UI warns about them). Email attachments are
 * only sent when the destination opts in (`email_attachments`) AND has a
 * password — plaintext backups are never emailed.
 *
 * Per-destination failures are isolated (continue-on-error) so one bad
 * destination cannot prevent the others from receiving the backup.
 */
class AutoBackupJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** @var int Seconds before the job is killed */
    public int $timeout = 300;

    /** @var int Attempts before giving up (one retry, with backoff) */
    public int $tries = 2;

    public function backoff(): int
    {
        return 120;
    }

    public function __construct(public User $user)
    {
    }

    public function handle(BackupService $backup, BackupDestinationService $destinations): void
    {
        $payload = $backup->generateEncryptedBackup($this->user);
        $filename = 'backup-' . now()->utc()->format('Y-m-d-His') . '.vault';

        $errors = [];

        /** @var \App\Models\UserBackupDestination $destination */
        foreach ($this->user->backupDestinations()->where('is_active', true)->get() as $destination) {
            try {
                $config = $destination->config ?? [];
                $password = $config['encryption_password'] ?? null;
                $password = is_string($password) && $password !== '' ? $password : null;

                if ($destination->type === 'email'
                    && !filter_var($config['email_attachments'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    // Explicit opt-in required — never email backups by default.
                    $destination->update([
                        'last_run_at'     => now(),
                        'last_run_status' => 'skipped',
                    ]);
                    continue;
                }

                if ($destination->type === 'email' && $password === null) {
                    // Opted in but no password configured: sending plaintext is
                    // not acceptable (C1) — mark as failed, keep the label only.
                    throw new \RuntimeException('email attachments require an encryption password');
                }

                $envelope = $password !== null
                    ? $backup->buildEncryptedEnvelope($payload, $password)
                    : $backup->buildLegacyEnvelope($payload);

                $destinations->send($destination, json_encode($envelope), $filename);
                $destination->update([
                    'last_run_at'     => now(),
                    'last_run_status' => 'success',
                ]);
            } catch (\Throwable $e) {
                $destination->update([
                    'last_run_at'     => now(),
                    'last_run_status' => 'failed',
                ]);
                // Credentials must never appear in logs/mails — only the label
                $errors[] = $destination->label;
            }
        }

        // Record run timestamp in user preferences (raw JSON column path)
        $this->user['preferences->last_auto_backup_at'] = now()->utc()->toIso8601String();
        $this->user->save();

        Mail::to($this->user->email)->send(new AutoBackupNotificationMail($filename, $errors));
    }
}
