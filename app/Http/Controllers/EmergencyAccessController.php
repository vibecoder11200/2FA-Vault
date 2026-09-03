<?php

namespace App\Http\Controllers;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use App\Services\EmergencyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class EmergencyAccessController extends Controller
{
    public function __construct(protected EmergencyAccessService $service) {}

    /** List own emergency contacts */
    public function index(): JsonResponse
    {
        $contacts = EmergencyContact::where('owner_id', Auth::id())
            ->with('trustedUser:id,name,email')
            ->withCount(['accessRequests as pending_requests' => fn ($q) => $q->where('status', 'pending')])
            ->get()
            ->map(function (EmergencyContact $contact) {
                // RT3: surface key staleness so the UI can nudge the owner to
                // re-save the wrapped key (missing key or rotated grantee key).
                $contact->has_encrypted_key = ! blank($contact->encrypted_key);
                $contact->key_stale = $this->service->isKeyStale($contact);

                return $contact;
            });

        return response()->json($contacts);
    }

    /** Designate a new trusted contact */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email'                          => 'required|email',
            'wait_days'                      => 'required|integer|in:7,14,30,60,90',
            'access_type'                    => 'required|in:view_only,full_access',
            'encrypted_key'                  => 'nullable|string',
            'grantee_public_key_fingerprint' => 'nullable|string|size:64',
        ]);

        try {
            $contact = $this->service->designateContact(
                Auth::user(),
                $validated['email'],
                $validated['wait_days'],
                $validated['access_type'],
                $validated['encrypted_key'] ?? null,
                $validated['grantee_public_key_fingerprint'] ?? null
            );
            return response()->json($contact->load('trustedUser:id,name,email'), 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Revoke a contact */
    public function destroy(int $id): JsonResponse
    {
        $contact = EmergencyContact::where('owner_id', Auth::id())->findOrFail($id);
        $this->service->revokeContact($contact);
        return response()->json(null, 204);
    }

    /**
     * Look up a prospective grantee's registered RSA public key (by email) so
     * the owner's client can wrap the vault key at designation time (F1).
     */
    public function granteeKeyInfo(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => 'required|email']);

        $grantee = User::where('email', $validated['email'])->first();

        if (! $grantee || blank($grantee->public_key)) {
            return response()->json(['message' => 'No registered public key for this email'], 404);
        }

        return response()->json([
            'user_id'     => $grantee->id,
            'public_key'  => $grantee->public_key,
            'fingerprint' => EmergencyAccessService::publicKeyFingerprint($grantee->public_key),
        ]);
    }

    /** Trusted contact requests access */
    public function requestAccess(Request $request, int $contactId): JsonResponse
    {
        // B13: throttle access requests (5/min) to prevent flooding the owner queue.
        if (! app()->runningInConsole()) {
            $key = 'emergency-request:' . Auth::id();

            if (RateLimiter::tooManyAttempts($key, 5)) {
                $seconds = RateLimiter::availableIn($key);

                return response()->json([
                    'message' => "Too many access requests. Please try again in {$seconds} seconds.",
                ], 429);
            }

            RateLimiter::hit($key, 60);
        }

        $contact = EmergencyContact::where('trusted_user_id', Auth::id())->findOrFail($contactId);

        try {
            $request = $this->service->requestAccess($contact);
            return response()->json($request, 201);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Owner approves a request */
    public function approve(Request $request, int $requestId): JsonResponse
    {
        $validated = $request->validate(['encrypted_key' => 'nullable|string']);
        $accessRequest = EmergencyAccessRequest::whereHas('contact', fn ($q) => $q->where('owner_id', Auth::id()))->findOrFail($requestId);

        try {
            $this->service->approveRequest($accessRequest, $validated['encrypted_key'] ?? null);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Access approved']);
    }

    /** Owner denies a request */
    public function deny(int $requestId): JsonResponse
    {
        $accessRequest = EmergencyAccessRequest::whereHas('contact', fn ($q) => $q->where('owner_id', Auth::id()))->findOrFail($requestId);

        try {
            $this->service->denyRequest($accessRequest);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Access denied']);
    }

    /** List pending access requests for owner to review */
    public function pendingRequests(): JsonResponse
    {
        $requests = EmergencyAccessRequest::whereHas('contact', fn ($q) => $q->where('owner_id', Auth::id()))
            ->where('status', 'pending')
            ->with(['contact:id,email,access_type,wait_days', 'requester:id,name,email'])
            ->get();

        return response()->json($requests);
    }

    /** List contacts where the authenticated user IS the trusted contact */
    public function contactsForMe(): JsonResponse
    {
        $contacts = EmergencyContact::where('trusted_user_id', Auth::id())
            ->whereIn('status', ['confirmed', 'active'])
            ->with('owner:id,name,email')
            ->get()
            ->map(function (EmergencyContact $contact) {
                // Expose the wrapped key ONLY to the grantee, and only for
                // active contacts (the vault-data endpoint re-verifies the
                // fingerprint and fails closed on staleness).
                if ($contact->status === 'active') {
                    $contact->makeVisible('encrypted_key');
                }
                $contact->has_encrypted_key = ! blank($contact->encrypted_key);

                return $contact;
            });

        return response()->json($contacts);
    }

    /**
     * Emergency vault data for the grantee (F1, read-only).
     *
     * Returns the owner's encrypted twofaccounts plus the wrapped vault key
     * and the owner's encryption salt (the grantee derives the decryption key
     * client-side from the wrapped master password + salt). Per access_type
     * semantics this is a read-only DATA DUMP in both cases: no OTP-generation
     * fields are needed — the grantee decrypts and displays the data locally.
     *
     * RT3 fail-closed: when the wrapped key is missing (including pre-existing
     * active rows created before keys were stored at designation) or the grantee
     * has rotated their public key since the key was wrapped, the endpoint
     * returns 409 `emergency_key_unavailable` instead of silently delivering
     * unusable data. The owner must re-save the contact.
     */
    public function vaultData(int $id): JsonResponse
    {
        /** @var EmergencyContact $contact */
        $contact = EmergencyContact::with('owner:id,name,email,encryption_salt,encryption_test_value')
            ->findOrFail($id);

        if ($contact->trusted_user_id !== Auth::id()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if ($contact->status !== 'active') {
            return response()->json(['message' => 'Emergency access is not active for this contact'], 409);
        }

        if ($this->service->isKeyStale($contact)) {
            return response()->json([
                'error'   => 'emergency_key_unavailable',
                'message' => 'The emergency key is unavailable or outdated. The owner must re-save this contact.',
            ], 409);
        }

        $accounts = \App\Models\TwoFAccount::where('user_id', $contact->owner_id)
            ->orderBy('order_column')
            ->get(['id', 'service', 'account', 'otp_type', 'secret', 'encrypted', 'digits', 'algorithm', 'counter', 'period', 'icon', 'group_id', 'last_used_at']);

        return response()->json([
            'contact_id'   => $contact->id,
            'owner'        => $contact->owner->only('id', 'name', 'email'),
            'access_type'  => $contact->access_type, // read-only data dump in both cases (view_only / full_access)
            'granted_at'   => $contact->granted_at?->toIso8601String(),
            'encrypted_key'               => $contact->encrypted_key,
            'grantee_public_key_fingerprint' => $contact->grantee_public_key_fingerprint,
            'owner_encryption_salt'       => $contact->owner->encryption_salt,
            'accounts'     => $accounts,
        ]);
    }
}
