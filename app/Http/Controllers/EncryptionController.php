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
     */
    public function setup(Request $request) : JsonResponse
    {
        // Rate limiting: max 3 attempts per minute (skip in console-driven automated tests)
        if (! app()->runningInConsole()) {
            $key = 'encryption-setup:' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, 3)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => "Too many setup attempts. Please try again in {$seconds} seconds.",
                ], 429);
            }

            RateLimiter::hit($key, 60);
        }

        $validated = $request->validate([
            'encryption_salt'       => 'required|string|max:255',
            'encryption_test_value' => 'required|string',
            'encryption_version'    => 'required|integer|min:1',
        ]);

        $user = Auth::user();

        // Check if user already has encryption setup
        if ($this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is already enabled for this account',
            ], 400);
        }

        try {
            $success = $this->encryptionService->setupEncryption(
                $user,
                $validated['encryption_salt'],
                $validated['encryption_test_value'],
                $validated['encryption_version']
            );

            if (! $success) {
                return response()->json([
                    'message' => 'Failed to setup encryption',
                ], 500);
            }

            Log::info('E2EE setup completed', [
                'user_id' => $user->id,
                'version' => $validated['encryption_version'],
            ]);

            return response()->json([
                'message'            => 'End-to-end encryption enabled successfully',
                'encryption_enabled' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('E2EE setup failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to setup encryption',
            ], 500);
        }
    }

    /**
     * Get encryption info for the authenticated user
     * Returns salt and test value needed for key derivation and verification
     */
    public function info() : JsonResponse
    {
        $user = Auth::user();
        $info = $this->encryptionService->getEncryptionInfo($user);

        return response()->json($info);
    }

    /**
     * Get encryption salt for key derivation
     * Client needs this for password-based key derivation
     */
    public function getSalt() : JsonResponse
    {
        $user = Auth::user();

        if (! $this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled',
            ], 400);
        }

        return response()->json([
            'encryption_salt' => $user->encryption_salt,
        ]);
    }

    /**
     * Verify master password (zero-knowledge verification)
     *
     * NOTE: This is a zero-knowledge verification endpoint.
     * The client derives the key and decrypts the test value locally.
     * This endpoint just confirms the result.
     */
    public function verify(Request $request) : JsonResponse
    {
        // Rate limiting: max 5 attempts per minute (skip in console-driven automated tests)
        if (! app()->runningInConsole()) {
            $key = 'encryption-verify:' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, 5)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => "Too many verification attempts. Please try again in {$seconds} seconds.",
                ], 429);
            }

            RateLimiter::hit($key, 60);
        }

        $validated = $request->validate([
            'verification_result' => 'required|boolean',
        ]);

        $user = Auth::user();

        // Verify encryption is enabled before allowing verification
        if (! $this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled for this account',
            ], 400);
        }

        if ($validated['verification_result']) {
            // Client successfully decrypted the test value
            // A4 disposition: vault_locked is client-attested by design
            // under E2EE — the server never sees the key material, so it can
            // only record the client's claim. See
            // docs/development/security-guidelines.md.
            $this->encryptionService->unlockVault($user);

            Log::info('Vault unlocked', ['user_id' => $user->id]);

            return response()->json([
                'message'      => 'Vault unlocked successfully',
                'vault_locked' => false,
            ]);
        }

        return response()->json([
            'message'      => 'Verification failed',
            'vault_locked' => true,
        ], 401);
    }

    /**
     * Lock the vault
     */
    public function lock() : JsonResponse
    {
        $user = Auth::user();

        if (! $this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled',
            ], 400);
        }

        $this->encryptionService->lockVault($user);

        Log::info('Vault locked', ['user_id' => $user->id]);

        return response()->json([
            'message'      => 'Vault locked successfully',
            'vault_locked' => true,
        ]);
    }

    /**
     * Update encryption credentials (B5: master-password rotation, step 1).
     *
     * The client derives a new key from the new master password + new salt,
     * re-encrypts the test value with it and submits both here. Step 2 is
     * bulkUpdateSecrets() with every account secret re-encrypted under the
     * new key (plus any emergency wrapped keys re-wrapped client-side).
     *
     * The server NEVER receives the master password or the key itself.
     */
    public function updateCredentials(Request $request) : JsonResponse
    {
        // Rate limiting: max 2 attempts per hour, like disable (skip in
        // console-driven automated tests)
        if (! app()->runningInConsole()) {
            $key = 'encryption-credentials:' . $request->user()->id;

            if (RateLimiter::tooManyAttempts($key, 2)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => 'Too many credential update attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.',
                ], 429);
            }

            RateLimiter::hit($key, 3600);
        }

        $validated = $request->validate([
            'encryption_salt'       => 'required|string|max:255',
            'encryption_test_value' => 'required|string',
            'encryption_version'    => 'nullable|integer|min:1',
        ]);

        $user = Auth::user();

        if (! $this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled',
            ], 400);
        }

        $success = $this->encryptionService->updateEncryptionCredentials(
            $user,
            $validated['encryption_salt'],
            $validated['encryption_test_value'],
            $validated['encryption_version'] ?? null
        );

        if (! $success) {
            return response()->json([
                'message' => 'Failed to update encryption credentials',
            ], 500);
        }

        Log::info('Encryption credentials rotated', ['user_id' => $user->id]);

        return response()->json([
            'message' => 'Encryption credentials updated successfully. Now submit the re-encrypted secrets.',
        ]);
    }

    /**
     * Bulk update encrypted secrets (B5: master-password rotation, step 2).
     *
     * Receives [{id, secret}] where each secret is a JSON envelope
     * {ciphertext, iv, authTag} produced by the client under the new key.
     */
    public function bulkUpdateSecrets(Request $request) : JsonResponse
    {
        $validated = $request->validate([
            'accounts'               => 'required|array|min:1',
            'accounts.*.id'          => 'required|integer',
            'accounts.*.secret'      => 'required|string',
        ]);

        $user = Auth::user();

        if (! $this->encryptionService->isEncryptionEnabled($user)) {
            return response()->json([
                'message' => 'Encryption is not enabled',
            ], 400);
        }

        $result = $this->encryptionService->bulkUpdateEncryptedSecrets($user, $validated['accounts']);

        return response()->json($result);
    }

    /**
     * Check if user has E2EE set up
     */
    public function checkEncryptionStatus() : JsonResponse
    {
        $user   = Auth::user();
        $status = $this->encryptionService->getEncryptionStatus($user);

        return response()->json($status);
    }

    /**
     * Disable E2EE (requires re-authentication and data migration)
     */
    public function disable(Request $request) : JsonResponse
    {
        // Rate limiting: max 2 attempts per hour (skip in console-driven automated tests)
        if (! app()->runningInConsole()) {
            $key = 'encryption-disable:' . $request->ip();

            if (RateLimiter::tooManyAttempts($key, 2)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => 'Too many disable attempts. Please try again in ' . ceil($seconds / 60) . ' minutes.',
                ], 429);
            }

            RateLimiter::hit($key, 3600);
        }

        $validated = $request->validate([
            'password' => 'required|string',
            'confirm'  => 'required|boolean|accepted',
        ]);

        $user = Auth::user();

        // Verify password
        if (! password_verify($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid password',
            ], 401);
        }

        try {
            $encryptedCount = $this->encryptionService->getEncryptedAccountCount($user);
            if ($encryptedCount > 0) {
                return response()->json([
                    'message'         => __('error.cannot_disable_encryption_with_accounts'),
                    'encrypted_count' => $encryptedCount,
                ], 422);
            }

            if (! $this->encryptionService->disableEncryption($user)) {
                return response()->json([
                    'message' => __('error.encryption_disable_failed'),
                ], 500);
            }

            Log::warning('E2EE disabled', ['user_id' => $user->id]);

            return response()->json([
                'message'            => __('message.encryption_disabled'),
                'encryption_enabled' => false,
            ]);
        } catch (\Exception $e) {
            Log::error('E2EE disable failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to disable encryption',
            ], 500);
        }
    }
}
