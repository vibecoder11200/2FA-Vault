<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BackupController extends Controller
{
    protected BackupService $backupService;

    public function __construct(BackupService $backupService)
    {
        $this->backupService = $backupService;
    }

    /**
     * Export encrypted backup
     *
     * Returns the backup PAYLOAD (format 2 marker). The SPA encrypts it
     * client-side with the user-typed backup password (Argon2id + AES-256-GCM)
     * and triggers the blob download (audit C1/C5). The server-side copy on
     * the `backups` disk stays APP_KEY-encrypted as a disaster-recovery
     * artifact.
     *
     * The legacy `password` request parameter is no longer part of the
     * contract (it was never used server-side) — it is silently ignored if
     * sent, and the legacy non-JSON GET path no longer accepts it either
     * (it used to leak into query-string logs).
     *
     * @param Request $request
     * @return StreamedResponse|JsonResponse
     */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        // Rate limiting: max 5 exports per hour (skip in testing)
        if (!app()->environment('testing')) {
            $key = 'backup-export:' . $request->user()->id;

            if (RateLimiter::tooManyAttempts($key, 5)) {
                $seconds = RateLimiter::availableIn($key);
                return response()->json([
                    'message' => "Too many export attempts. Please try again in " . ceil($seconds / 60) . " minutes."
                ], 429);
            }

            RateLimiter::hit($key, 3600);
        }

        $validated = $request->validate([
            'include_groups' => 'nullable|boolean',
        ]);

        $user = Auth::user();

        try {
            // Generate backup payload (accounts still encrypted with user's master key)
            $includeGroups = $validated['include_groups'] ?? true;
            $backupData = $this->backupService->generateEncryptedBackup($user, $includeGroups);

            // Update last backup timestamp
            $user->last_backup_at = now();
            $user->save();

            Log::info('Backup exported', [
                'user_id' => $user->id,
                'account_count' => $backupData['account_count'] ?? 0,
                'groups_included' => $includeGroups,
            ]);

            // C13: include the user id — same-second exports by different
            // users used to collide on the `backups` disk.
            $filename = sprintf(
                '2fa-vault-backup-u%s-%s.vault',
                $user->id,
                now()->format('Y-m-d-His')
            );
            $backupJson = json_encode($backupData, JSON_PRETTY_PRINT);

            // Store backup file encrypted at rest
            $encrypted = Crypt::encryptString($backupJson);
            Storage::disk('backups')->put($filename, $encrypted);

            // For JSON/SPA clients: return the payload so the frontend can
            // encrypt it client-side and deliver the actual file (C5).
            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json([
                    'filename' => $filename,
                    'format' => BackupService::ENVELOPE_FORMAT_V2,
                    'size' => strlen($backupJson),
                    'account_count' => $backupData['account_count'] ?? 0,
                    'group_count' => isset($backupData['groups']) ? count($backupData['groups']) : 0,
                    'payload' => $backupData,
                ]);
            }

            // Legacy non-JSON path: return the plaintext payload as a
            // downloadable file (no password involved).
            return response()->streamDownload(function () use ($backupJson) {
                echo $backupJson;
            }, $filename, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"'
            ]);

        } catch (\Exception $e) {
            Log::error('Backup export failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => __('error.backup_export_failed')
            ], 500);
        }
    }

    /**
     * Import encrypted backup
     *
     * Client decrypts the backup file with backup password first,
     * then sends the decrypted data here. Each account secret is
     * still encrypted with the user's master key.
     *
     * SECURITY NOTE: For vault-format backups, the client decrypts the backup
     * file client-side and sends the decrypted payload here. Each account secret
     * is still encrypted with the user's E2EE master key, but the backup metadata
     * (account names, groups, tags) is exposed in the request. This is a known
     * limitation of the current architecture. A future improvement would have
     * the client send individual encrypted accounts instead of the full blob.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function import(Request $request): JsonResponse
    {
        // Rate limiting: max 3 imports per hour (skip in testing)
        if (!app()->environment('testing')) {
            $key = 'backup-import:' . $request->user()->id;

            if (RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = RateLimiter::availableIn($key);
                return response()->json([
                    'message' => "Too many import attempts. Please try again in " . ceil($seconds / 60) . " minutes."
                ], 429);
            }

            RateLimiter::hit($key, 3600);
        }

        // Build validation rules. The `password` field is gone: vault-format
        // files are decrypted client-side before upload (RT4); a legacy
        // `password` value sent by old clients is silently ignored.
        $rules = [
            'backup_file' => 'required|file|max:10240',
            'format' => $this->backupService->backupFormatValidationRule(),
            'conflict_resolution' => 'nullable|in:skip,replace,rename',
            'import_groups' => 'nullable|boolean',
        ];

        $validated = $request->validate($rules);

        $user = Auth::user();

        try {
            // C8: read + decode the uploaded file once.
            $file = $request->file('backup_file');
            $backupData = json_decode($file->get(), true);

            if (!is_array($backupData)) {
                return response()->json([
                    'message' => 'Invalid backup file',
                    'errors' => ['backup_file' => ['The file is not valid JSON']]
                ], 422);
            }

            // RT4: a format >= 2 envelope still carrying ciphertext (no
            // top-level accounts) was NOT decrypted client-side. Refuse with
            // a clear error — never fall back to the legacy plaintext path.
            if ($this->backupService->isUndecryptedV2Envelope($backupData)) {
                return response()->json([
                    'message' => 'This backup file is password-encrypted. Decrypt it in the app first, then import the decrypted backup.',
                    'errors' => ['backup_file' => ['The backup file must be decrypted in the app before import']]
                ], 422);
            }

            // Determine format from file content or explicit parameter
            $explicitFormat = $validated['format'] ?? null;
            $finalFormat = $this->backupService->normalizeImportFormat(
                $explicitFormat ?? $this->backupService->detectImportFormat($backupData)
            );

            if (!$this->backupService->isImportFormatSupported($finalFormat)) {
                return response()->json([
                    'message' => 'Invalid format selected',
                    'errors' => ['format' => ['The selected format is not supported']]
                ], 422);
            }

            // Validate backup structure against selected format
            if (!$this->backupService->validateImportPayload($backupData, $finalFormat)) {
                return response()->json([
                    'message' => $this->backupService->importValidationErrorMessage($finalFormat),
                    'errors' => ['backup_file' => [$this->backupService->importValidationErrorDetail($finalFormat)]]
                ], 422);
            }

            // Import options
            $options = [
                'conflict_resolution' => $validated['conflict_resolution'] ?? 'skip',
                'import_groups' => $validated['import_groups'] ?? true,
            ];

            // RT4: imports without a numeric format marker follow the legacy
            // plaintext path — surface a warning banner in the summary.
            $legacyWarning = $this->backupService->isVaultFormat($finalFormat)
                && $this->backupService->isLegacyVaultImport($backupData);

            // Restore backup
            $result = $this->backupService->restoreEncryptedBackup(
                $user,
                $backupData,
                $finalFormat,
                $options
            );

            Log::info('Backup imported', [
                'user_id' => $user->id,
                'format' => $finalFormat,
                'imported_count' => $result['imported'],
                'skipped_count' => $result['skipped'],
                'failed_count' => $result['failed'],
                'conflict_resolution' => $result['conflict_resolution'],
            ]);

            return response()->json([
                'imported_count' => $result['imported'],
                'skipped_count' => $result['skipped'] ?? 0,
                'failed_count' => $result['failed'],
                'errors' => $result['errors'] ?? [],
                'conflict_resolution' => $result['conflict_resolution'] ?? 'skip',
                'encrypted_count' => $result['encrypted_count'] ?? 0,
                'key_mismatch_warning' => $result['key_mismatch_warning'] ?? false,
                'legacy_format_warning' => $legacyWarning,
                // C3: lets the SPA attempt decryption and offer one-click
                // deletion of just-imported undecryptable accounts.
                'imported_account_ids' => $result['imported_ids'] ?? [],
            ]);

        } catch (\Exception $e) {
            Log::error('Backup import failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => __('error.backup_import_failed'),
                'errors' => ['backup_file' => [__('error.backup_import_failed')]]
            ], 422);
        }
    }

    /**
     * Get backup metadata without decrypting
     *
     * Returns preview information about a backup file.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function metadata(Request $request): JsonResponse
    {
        // C13: mimes content-sniffing rejected valid files whose extension was
        // right but whose content-type guess failed — validate by extension.
        $validated = $request->validate([
            'backup_file' => [
                'required',
                'file',
                'max:10240',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (!in_array(strtolower($value->getClientOriginalExtension()), ['vault', 'json'], true)) {
                        $fail('The backup file must be a .vault or .json file.');
                    }
                },
            ],
        ]);

        try {
            $file = $request->file('backup_file');
            $backupData = json_decode($file->get(), true);

            if (!is_array($backupData)) {
                return response()->json([
                    'message' => 'Invalid backup file format'
                ], 400);
            }

            $metadata = $this->backupService->getBackupMetadata($backupData);

            return response()->json($metadata);

        } catch (\Exception $e) {
            Log::error('Failed to read backup metadata', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => __('error.backup_metadata_failed')
            ], 400);
        }
    }

    /**
     * Get user's backup information and statistics
     *
     * @return JsonResponse
     */
    public function info(): JsonResponse
    {
        $user = Auth::user();

        $stats = $this->backupService->getBackupStats($user);

        return response()->json($stats);
    }
}
