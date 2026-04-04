<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class EncryptionController extends Controller
{
    /**
     * Setup E2EE for the authenticated user
     * 
     * IMPORTANT: This endpoint receives ONLY:
     * - encryption_salt (for key derivation)
     * - encryption_test_value (encrypted test data for verification)
     * 
     * The server NEVER receives:
     * - The master password
     * - The encryption key
     * - Any plaintext secrets
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function setup(Request $request): JsonResponse
    {
        // Rate limiting: max 3 attempts per minute
        $key = 'encryption-setup:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many setup attempts. Please try again in {$seconds} seconds."
            ], 429);
        }
        
        RateLimiter::hit($key, 60);
        
        $validated = $request->validate([
            'encryption_salt' => 'required|string|max:255',
            'encryption_test_value' => 'required|string',
            'encryption_version' => 'required|integer|min:1'
        ]);
        
        $user = Auth::user();
        
        // Check if user already has encryption setup
        if ($user->encryption_version > 0) {
            return response()->json([
                'message' => 'Encryption is already enabled for this account'
            ], 400);
        }
        
        try {
            // Store salt and test value (NOT the password or key!)
            $user->encryption_salt = $validated['encryption_salt'];
            $user->encryption_test_value = $validated['encryption_test_value'];
            $user->encryption_version = $validated['encryption_version'];
            $user->vault_locked = false; // Vault is unlocked after setup
            $user->save();
            
            Log::info('E2EE setup completed', [
                'user_id' => $user->id,
                'version' => $validated['encryption_version']
            ]);
            
            return response()->json([
                'message' => 'End-to-end encryption enabled successfully',
                'encryption_enabled' => true
            ]);
        } catch (\Exception $e) {
            Log::error('E2EE setup failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to setup encryption'
            ], 500);
        }
    }
    
    /**
     * Get encryption info for the authenticated user
     * Returns salt and test value needed for key derivation and verification
     * 
     * @return JsonResponse
     */
    public function info(): JsonResponse
    {
        $user = Auth::user();
        
        if ($user->encryption_version === 0) {
            return response()->json([
                'encryption_enabled' => false
            ]);
        }
        
        return response()->json([
            'encryption_enabled' => true,
            'encryption_salt' => $user->encryption_salt,
            'encryption_test_value' => $user->encryption_test_value,
            'encryption_version' => $user->encryption_version,
            'vault_locked' => $user->vault_locked
        ]);
    }
    
    /**
     * Verify master password (zero-knowledge verification)
     * 
     * NOTE: This is a zero-knowledge verification endpoint.
     * The client derives the key and decrypts the test value locally.
     * This endpoint just confirms the result.
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function verify(Request $request): JsonResponse
    {
        // Rate limiting: max 5 attempts per minute
        $key = 'encryption-verify:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many verification attempts. Please try again in {$seconds} seconds."
            ], 429);
        }
        
        RateLimiter::hit($key, 60);
        
        $validated = $request->validate([
            'verification_result' => 'required|boolean'
        ]);
        
        $user = Auth::user();
        
        if ($validated['verification_result']) {
            // Client successfully decrypted the test value
            $user->vault_locked = false;
            $user->save();
            
            Log::info('Vault unlocked', ['user_id' => $user->id]);
            
            return response()->json([
                'message' => 'Vault unlocked successfully',
                'vault_locked' => false
            ]);
        }
        
        return response()->json([
            'message' => 'Verification failed',
            'vault_locked' => true
        ], 401);
    }
    
    /**
     * Lock the vault
     * 
     * @return JsonResponse
     */
    public function lock(): JsonResponse
    {
        $user = Auth::user();
        
        if ($user->encryption_version === 0) {
            return response()->json([
                'message' => 'Encryption is not enabled'
            ], 400);
        }
        
        $user->vault_locked = true;
        $user->save();
        
        Log::info('Vault locked', ['user_id' => $user->id]);
        
        return response()->json([
            'message' => 'Vault locked successfully',
            'vault_locked' => true
        ]);
    }
    
    /**
     * Check if user has E2EE set up
     * 
     * @return JsonResponse
     */
    public function checkEncryptionStatus(): JsonResponse
    {
        $user = Auth::user();
        $encryptionEnabled = $user->encryption_version > 0;
        
        return response()->json([
            'encryption_enabled' => $encryptionEnabled,
            'encryption_version' => $user->encryption_version,
            'vault_locked' => $encryptionEnabled ? $user->vault_locked : false,
            'has_backup' => !is_null($user->last_backup_at),
            'last_backup_at' => $user->last_backup_at?->toIso8601String(),
            'should_prompt_setup' => !$encryptionEnabled && config('2fauth.settings.encryptionEnabledByDefault', true)
        ]);
    }
    
    /**
     * Disable E2EE (requires re-authentication and data migration)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function disable(Request $request): JsonResponse
    {
        // Rate limiting: max 2 attempts per hour
        $key = 'encryption-disable:' . $request->ip();
        
        if (RateLimiter::tooManyAttempts($key, 2)) {
            $seconds = RateLimiter::availableIn($key);
            return response()->json([
                'message' => "Too many disable attempts. Please try again in " . ceil($seconds / 60) . " minutes."
            ], 429);
        }
        
        RateLimiter::hit($key, 3600);
        
        $validated = $request->validate([
            'password' => 'required|string',
            'confirm' => 'required|boolean|accepted'
        ]);
        
        $user = Auth::user();
        
        // Verify password
        if (!password_verify($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid password'
            ], 401);
        }
        
        try {
            // Clear encryption settings
            $user->encryption_salt = null;
            $user->encryption_test_value = null;
            $user->encryption_version = 0;
            $user->vault_locked = false;
            $user->save();
            
            Log::warning('E2EE disabled', ['user_id' => $user->id]);
            
            return response()->json([
                'message' => 'Encryption disabled successfully',
                'encryption_enabled' => false
            ]);
        } catch (\Exception $e) {
            Log::error('E2EE disable failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            
            return response()->json([
                'message' => 'Failed to disable encryption'
            ], 500);
        }
    }
}
