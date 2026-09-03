<?php

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserBackupDestination;
use App\Services\BackupDestinationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * BackupDestinationServiceTest test class
 *
 * The service only reads $destination->type and $destination->config in memory,
 * so tests build unsaved models to isolate the service from DB persistence.
 */
#[CoversClass(BackupDestinationService::class)]
class BackupDestinationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeDestination(string $type, array $config): UserBackupDestination
    {
        $dest = new UserBackupDestination();
        $dest->user_id = User::factory()->create()->id;
        $dest->label = 'test';
        $dest->type = $type;
        $dest->is_active = true;
        // Assign through the mutator so config is held exactly like a real
        // row (CanEncryptField always encrypts it) and reads back as the
        // decoded array the service expects.
        $dest->config = $config;

        return $dest;
    }

    #[Test]
    public function test_send_local_writes_file_to_local_disk()
    {
        Storage::fake('local');

        $destination = $this->makeDestination('local', ['path' => 'backups']);

        (new BackupDestinationService())->send($destination, 'payload-content', 'my-backup.vault');

        Storage::disk('local')->assertExists('backups/my-backup.vault');
        $this->assertSame(
            'payload-content',
            Storage::disk('local')->get('backups/my-backup.vault')
        );
    }

    #[Test]
    public function test_send_throws_for_unknown_destination_type()
    {
        $this->expectException(\RuntimeException::class);

        $destination = $this->makeDestination('unknown-type', []);

        (new BackupDestinationService())->send($destination, 'x', 'y');
    }

    #[Test]
    public function test_send_email_dispatches_mail()
    {
        Mail::fake();

        $destination = $this->makeDestination('email', ['email' => 'recipient@synthetic.example']);

        (new BackupDestinationService())->send($destination, 'payload', 'backup.vault');

        Mail::assertSent(\App\Mail\BackupAttachmentMail::class, function ($mail) {
            return $mail->hasTo('recipient@synthetic.example');
        });
    }

    #[Test]
    public function test_test_returns_ok_true_when_send_succeeds()
    {
        Storage::fake('local');

        $destination = $this->makeDestination('local', ['path' => 'probes']);

        $result = (new BackupDestinationService())->test($destination);

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['message']);
    }

    #[Test]
    public function test_test_returns_ok_false_without_leaking_credentials_on_failure()
    {
        // The s3 driver tries to build a live client and fails; test() must catch
        // the error and return ok=false with NO credential in the message.
        $destination = $this->makeDestination('unknown-type', ['secret_key' => 'SUPER_SECRET_KEY_123']);

        $result = (new BackupDestinationService())->test($destination);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString('SUPER_SECRET_KEY_123', $result['message']);
    }
}
