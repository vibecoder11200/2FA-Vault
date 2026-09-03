<?php

namespace App\Services;

use App\Models\UserBackupDestination;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Sends a backup payload to a user-configured destination.
 * Each destination type is handled in isolation — a failure in one
 * must not abort the others (continue-on-error).
 */
class BackupDestinationService
{
    /**
     * Send the payload to the destination of the configured type.
     *
     * @param  UserBackupDestination  $destination
     * @param  string  $payload  Raw backup file contents
     * @param  string  $filename
     */
    public function send(UserBackupDestination $destination, string $payload, string $filename): void
    {
        $config = $destination->config ?? [];

        match ($destination->type) {
            'local'  => $this->sendLocal($config, $payload, $filename),
            's3'     => $this->sendS3($config, $payload, $filename),
            'email'  => $this->sendEmail($config, $payload, $filename),
            'webdav' => $this->sendWebDav($config, $payload, $filename),
            default  => throw new \RuntimeException('Unknown destination type: ' . $destination->type),
        };
    }

    /**
     * Best-effort connection test: writes a tiny probe file then deletes it.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(UserBackupDestination $destination): array
    {
        $probe = '2favault-probe-' . Str::random(8) . '.txt';

        try {
            $this->send($destination, '2FA-Vault connection probe', $probe);
            $this->delete($destination, $probe);

            return ['ok' => true, 'message' => 'Connection successful'];
        } catch (\Throwable $e) {
            // C11: error details (which may embed credentials from URLs) are
            // logged server-side only — the client gets a generic message.
            \Illuminate\Support\Facades\Log::warning('Backup destination connection test failed', [
                'destination_id' => $destination->id,
                'type'           => $destination->type,
                'error'          => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => 'Connection failed. Check the server logs for details.'];
        }
    }

    /**
     * Remove a file from the destination (used to clean up probe files).
     */
    public function delete(UserBackupDestination $destination, string $filename): void
    {
        $config = $destination->config ?? [];

        try {
            match ($destination->type) {
                'local'  => Storage::disk('local')->delete(($config['path'] ?? '') . '/' . $filename),
                's3'     => $this->resolveS3Disk($config)->delete(($config['prefix'] ?? '') . $filename),
                'webdav' => $this->resolveWebDavDisk($config)->delete($filename),
                default  => null,
            };
        } catch (\Throwable) {
            // Best-effort cleanup — ignore failures
        }
    }

    private function sendLocal(array $config, string $payload, string $filename): void
    {
        $dir = str_replace('\\', '/', (string) ($config['path'] ?? 'backups'));

        // C13: traversal guard — reject any '..' segment (ltrim('/.') alone
        // let "storage/../.." escape) and normalize away '.'/'empty' parts.
        $segments = array_values(array_filter(
            explode('/', $dir),
            fn (string $segment) => $segment !== '' && $segment !== '.'
        ));

        if (in_array('..', $segments, true)) {
            throw new \RuntimeException('Invalid backup destination path');
        }

        Storage::disk('local')->put(implode('/', $segments) . '/' . $filename, $payload);
    }

    private function sendS3(array $config, string $payload, string $filename): void
    {
        $this->resolveS3Disk($config)->put(($config['prefix'] ?? '') . $filename, $payload);
    }

    private function sendWebDav(array $config, string $payload, string $filename): void
    {
        $this->resolveWebDavDisk($config)->put($filename, $payload);
    }

    private function sendEmail(array $config, string $payload, string $filename): void
    {
        Mail::to($config['email'])->send(new \App\Mail\BackupAttachmentMail($payload, $filename));
    }

    private function resolveS3Disk(array $config)
    {
        return Storage::build([
            'driver'                  => 's3',
            'key'                     => $config['access_key'],
            'secret'                  => $config['secret_key'],
            'region'                  => $config['region'] ?? 'us-east-1',
            'bucket'                  => $config['bucket'],
            'endpoint'                => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['endpoint'] ?? false),
            // C11: bounded HTTP timings so a hung endpoint cannot burn the
            // whole 300 s job timeout.
            'http'                    => [
                'timeout'         => 60,
                'connect_timeout' => 10,
            ],
            'throw'                   => true,
        ]);
    }

    private function resolveWebDavDisk(array $config)
    {
        return Storage::build([
            'driver'     => 'webdav',
            'baseUri'    => $config['url'],
            'userName'   => $config['username'],
            'password'   => $config['password'],
            'pathPrefix' => $config['path'] ?? '',
            // C11: same bounded timings as S3 (applied via curl settings in
            // the custom webdav driver, see AppServiceProvider).
            'timeout'         => 60,
            'connect_timeout' => 10,
        ]);
    }
}
