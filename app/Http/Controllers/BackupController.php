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
        // Rate limiting: max 5 exports per hour
        $key = 'backup-export:' . $request->user()->id;
        
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many export attempts. Please try again in " . ceil($seconds / 60) . " minutes."
            ], 429);
        }
        
        RateLimiter::hit($key, 3600);
        
        $validated = $request->validate([
            'encryption_key_hash' => 'required|string', // Client sends derived key hash for verification
            'master_password_verified' => 'required|boolean|accepted'
        ]);
        
        $user = Auth::user();
        
        // Check if user has E2EE enabled
        if ($user->encryption_version === 0) {
            return response()->json([
                'message' => 'Encryption is not enabled. Please enable encryption first.'
            ], 400);
        }
        
        try {
            // Generate encrypted backup (client-side encryption key will be used)
            $backupData = $this->backupService->generateEncryptedBackup($user);
            
            // Update last backup timestamp
            $user->last_backup_at = now();
            $user->save();
            
            Log::info('Backup exported', [
                'user_id' => $user->id,
                'account_count' => $backupData['accountCount']
            ]);
            
            // Return as downloadable file
            $filename = '2fauth-backup-' . now()->format('Y-m-d-His') . '.vault';
            
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
        // Rate limiting: max 3 imports per hour
        $key = 'backup-import:' . $request->user()->id;
        
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many import attempts. Please try again in " . ceil($seconds / 60) . " minutes."
            ], 429);
        }
        
        RateLimiter::hit($key, 3600);
        
        $validated = $request->validate([
            'backup_file' => 'required|file|mimes:vault,json|max:10240', // Max 10MB
            'mode' => 'required|in:merge,replace',
            'master_password_verified' => 'required|boolean|accepted'
        ]);
        
        $user = Auth::user();
        
        try {
            // Validate backup file
            $file = $request->file('backup_file');
            $backupData = json_decode($file->get(), true);
            
            if (!$this->backupService->validateBackupFile($backupData)) {
                return response()->json([
                    'message' => 'Invalid backup file format'
                ], 400);
            }
            
            // Check version compatibility
            if (!isset($backupData['version']) || version_compare($backupData['version'], '2.0', '<')) {
                return response()->json([
                    'message' => 'Backup version not supported. Please use a newer backup.'
                ], 400);
            }
            
            // Restore backup (client already decrypted)
            $result = $this->backupService->restoreEncryptedBackup(
                $user,
                $backupData,
                $validated['mode']
            );
            
            Log::info('Backup imported', [
                'user_id' => $user->id,
                'mode' => $validated['mode'],
                'imported_count' => $result['imported'],
                'failed_count' => $result['failed']
            ]);
            
            return response()->json([
                'message' => 'Backup imported successfully',
                'imported' => $result['imported'],
                'failed' => $result['failed'],
                'errors' => $result['errors']
            ]);
            
        } catch (\Exception $e) {
            Log::error('Backup import failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to import backup: ' . $e->getMessage()
            ], 500);
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
