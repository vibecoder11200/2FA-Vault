<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a backup envelope as a mail attachment.
 *
 * The attachment is a password-encrypted format 2 envelope (AES-256-GCM with
 * an Argon2id-derived key) — AutoBackupJob only reaches this mail for
 * destinations that opted in AND configured an encryption password; the
 * backup password itself is NOT included, so the file is unreadable to the
 * mail transport.
 */
class BackupAttachmentMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $payload,
        public string $filename,
    ) {
    }

    public function build(): static
    {
        return $this->subject('Your 2FA-Vault encrypted backup')
            ->view('mail.backup-attachment')
            ->with(['filename' => $this->filename])
            ->attachData($this->payload, $this->filename, [
                'mime' => 'application/octet-stream',
            ]);
    }
}
