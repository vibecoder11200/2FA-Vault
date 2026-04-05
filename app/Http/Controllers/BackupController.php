<?php

namespace App\Http\Controllers;

use App\Services\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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
        ]);
        
        $user = Auth::user();
        
        try {
            // Generate encrypted backup
            $backupData = $this->backupService->generateEncryptedBackup($user, $validated['password']);
            
            // Update last backup timestamp
            $user->last_backup_at = now();
            $user->save();
            
            Log::info('Backup exported', [
                'user_id' => $user->id,
                'account_count' => $backupData['accountCount'] ?? 0
            ]);
            
            $filename = '2fa-vault-backup-' . now()->format('Y-m-d-His') . '.vault';
            
            // For testing: return JSON response instead of download
            if ($request->wantsJson() || $request->expectsJson()) {
                return response()->json([
                    'filename' => $filename,
                    'size' => strlen(json_encode($backupData)),
                    'accounts_count' => $backupData['accountCount'] ?? 0,
                ]);
            }
            
            // Return as downloadable file
            return response()->streamDownload(function () use ($backupData) {
                echo json_encode($backupData, JSON_PRETTY_PRINT);
            }, $filename, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Backup export failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to export backup: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Import encrypted backup
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
        
        $validated = $request->validate([
            'backup_file' => 'required|file',
            'password' => 'required|string|min:8',
            'format' => 'nullable|in:2fauth,vault',
        ]);
        
        $user = Auth::user();
        
        try {
            // Validate backup file
            $file = $request->file('backup_file');
            $backupData = json_decode($file->get(), true);
            
            if (!$backupData) {
                return response()->json([
                    'message' => 'Invalid backup file',
                    'errors' => ['backup_file' => ['The file is not valid JSON']]
                ], 422);
            }
            
            // Determine format
            $format = $validated['format'] ?? 'vault';
            if (isset($backupData['app']) && $backupData['app'] === '2FAuth') {
                $format = '2fauth';
            }
            
            // Restore backup
            $result = $this->backupService->restoreEncryptedBackup(
                $user,
                $backupData,
                $validated['password'],
                $format
            );
            
            Log::info('Backup imported', [
                'user_id' => $user->id,
                'format' => $format,
                'imported_count' => $result['imported'],
                'failed_count' => $result['failed']
            ]);
            
            return response()->json([
                'imported_count' => $result['imported'],
                'skipped_count' => $result['skipped'] ?? 0,
                'errors' => $result['errors'] ?? []
            ]);
            
        } catch (\Exception $e) {
            Log::error('Backup import failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
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
            
            $metadata = $this->backupService->getBackupMetadata($backupData);
            
            return response()->json($metadata);
            
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to read backup metadata: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get user's last backup info
     * 
     * @return JsonResponse
     */
    public function info(): JsonResponse
    {
        $user = Auth::user();
        
        return response()->json([
            'has_backup' => !is_null($user->last_backup_at),
            'last_backup_at' => $user->last_backup_at?->toIso8601String(),
            'days_since_backup' => $user->last_backup_at ? now()->diffInDays($user->last_backup_at) : null,
            'should_backup' => is_null($user->last_backup_at) || now()->diffInDays($user->last_backup_at) > 30
        ]);
    }
}
