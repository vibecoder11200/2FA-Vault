<?php

namespace App\Http\Controllers;

use App\Services\EncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class EncryptionController extends Controller
{
    protected EncryptionService $encryptionService;

    public function __construct(EncryptionService $encryptionService)
    {
        $this->encryptionService = $encryptionService;
    }

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
        // Rate limiting: max 3 attempts per minute (skip in console-driven automated tests)
        if (!app()->runningInConsole()) {
            $key = 'encryption-setup:' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = RateLimiter::availableIn($key);
                return response()->json([
                    'message' => "Too many setup attempts. Please try again in {$seconds} seconds."
                ], 429);
            }

            RateLimiter::hit($key, 60);
        }

        $validated = $request->validate([
            'encryption_salt' => 'required|string|max:255',
            'encryption_test_value' => 'required|string',
            'encryption_version' => 'required|integer|min:1'
        ]);

        $user = Auth::user();

        // Check if user already has encryption setup
        if ($this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is already enabled for this account'
            ], 400);
        }

        try {
            $success = $this->encryptionService->setupEncryption(
                $user,
                $validated['encryption_salt'],
                $validated['encryption_test_value'],
                $validated['encryption_version']
            );

            if (!$success) {
                return response()->json([
                    'message' => 'Failed to setup encryption'
                ], 500);
            }

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
        $info = $this->encryptionService->getEncryptionInfo($user);

        return response()->json($info);
    }

    /**
     * Get encryption salt for key derivation
     * Client needs this for password-based key derivation
     *
     * @return JsonResponse
     */
    public function getSalt(): JsonResponse
    {
        $user = Auth::user();

        if (!$this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled'
            ], 400);
        }

        return response()->json([
            'encryption_salt' => $user->encryption_salt
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
        // Rate limiting: max 5 attempts per minute (skip in console-driven automated tests)
        if (!app()->runningInConsole()) {
            $key = 'encryption-verify:' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, 5)) {
                $seconds = RateLimiter::availableIn($key);
                return response()->json([
                    'message' => "Too many verification attempts. Please try again in {$seconds} seconds."
                ], 429);
            }

            RateLimiter::hit($key, 60);
        }

        $validated = $request->validate([
            'verification_result' => 'required|boolean'
        ]);

        $user = Auth::user();

        // Verify encryption is enabled before allowing verification
        if (!$this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled for this account'
            ], 400);
        }

        if ($validated['verification_result']) {
            // Client successfully decrypted the test value
            $this->encryptionService->unlockVault($user);

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

        if (!$this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled'
            ], 400);
        }

        $this->encryptionService->lockVault($user);

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
        $status = $this->encryptionService->getEncryptionStatus($user);

        return response()->json($status);
    }

    /**
     * Disable E2EE (requires re-authentication and data migration)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function disable(Request $request): JsonResponse
    {
        // Rate limiting: max 2 attempts per hour (skip in console-driven automated tests)
        if (!app()->runningInConsole()) {
            $key = 'encryption-disable:' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, 2)) {
                $seconds = RateLimiter::availableIn($key);
                return response()->json([
                    'message' => "Too many disable attempts. Please try again in " . ceil($seconds / 60) . " minutes."
                ], 429);
            }

            RateLimiter::hit($key, 3600);
        }

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
            $this->encryptionService->disableEncryption($user);

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
