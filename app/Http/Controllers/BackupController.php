<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
     * The server retrieves encrypted accounts and packages them.
     * The client then encrypts the entire package with a backup password.
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
            'password' => 'required|string|min:8',
            'include_groups' => 'nullable|boolean',
        ]);

        $user = Auth::user();

        try {
            // Generate backup structure (accounts still encrypted with user's master key)
            $includeGroups = $validated['include_groups'] ?? true;
            $backupData = $this->backupService->generateEncryptedBackup($user, $includeGroups);

            // Update last backup timestamp
            $user->last_backup_at = now();
            $user->save();

            Log::info('Backup exported', [
                'user_id' => $user->id,
                'account_count' => $backupData['accountCount'] ?? 0,
                'groups_included' => $includeGroups,
            ]);

            $filename = '2fa-vault-backup-' . now()->format('Y-m-d-His') . '.vault';
            $backupJson = json_encode($backupData, JSON_PRETTY_PRINT);

            // Store backup file for later retrieval and testing
            Storage::put('backups/' . $filename, $backupJson);

            // For testing: return JSON response instead of download
            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json([
                    'filename' => $filename,
                    'size' => strlen($backupJson),
                    'accounts_count' => $backupData['accountCount'] ?? 0,
                    'groups_count' => isset($backupData['groups']) ? count($backupData['groups']) : 0,
                ]);
            }

            // Return as downloadable file
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
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to export backup: ' . $e->getMessage()
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

        // Build validation rules - password only required for vault format
        $rules = [
            'backup_file' => 'required|file',
            'format' => 'nullable|in:2fauth,vault,aegis,bitwarden',
            'conflict_resolution' => 'nullable|in:skip,replace,rename',
            'import_groups' => 'nullable|boolean',
        ];

        // Password required only for vault format (we'll detect format after validation)
        $rules['password'] = 'nullable|string|min:8';

        $validated = $request->validate($rules);

        $user = Auth::user();

        try {
            // Read and validate backup file
            $file = $request->file('backup_file');
            $backupData = json_decode($file->get(), true);

            if (!$backupData) {
                return response()->json([
                    'message' => 'Invalid backup file',
                    'errors' => ['backup_file' => ['The file is not valid JSON']]
                ], 422);
            }

            // Determine format from file content or explicit parameter
            $detectedFormat = 'vault'; // Default
            if (isset($backupData['app']) && $backupData['app'] === '2FAuth') {
                $detectedFormat = '2fauth';
            } elseif (isset($backupData['format']) && $backupData['format'] === '2FA-Vault') {
                $detectedFormat = 'vault';
            }

            $explicitFormat = $validated['format'] ?? null;
            $finalFormat = $explicitFormat ?? $detectedFormat;

            // Validate password requirement for vault format
            if ($finalFormat === 'vault' && empty($validated['password'])) {
                return response()->json([
                    'message' => 'The password field is required.',
                    'errors' => ['password' => ['The password field is required.']]
                ], 422);
            }

            // Validate backup structure
            if (!$this->backupService->validateBackupFile($backupData)) {
                return response()->json([
                    'message' => 'Invalid backup format or version not supported',
                    'errors' => ['backup_file' => ['The backup file format is invalid or from an unsupported version']]
                ], 422);
            }

            // Import options
            $options = [
                'conflict_resolution' => $validated['conflict_resolution'] ?? 'skip',
                'import_groups' => $validated['import_groups'] ?? true,
            ];

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
            ]);

        } catch (\Exception $e) {
            Log::error('Backup import failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to import backup: ' . $e->getMessage(),
                'errors' => ['backup_file' => [$e->getMessage()]]
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
        $validated = $request->validate([
            'backup_file' => 'required|file|mimes:vault,json|max:10240'
        ]);

        try {
            $file = $request->file('backup_file');
            $backupData = json_decode($file->get(), true);

            if (!$backupData) {
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
                'message' => 'Failed to read backup metadata: ' . $e->getMessage()
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
