<?php

namespace App\Services;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class EmergencyAccessService
{
    const MAX_CONTACTS = 5;

    /**
     * SHA-256 fingerprint (hex) of a base64 SPKI RSA public key.
     */
    public static function publicKeyFingerprint(string $publicKeyBase64) : string
    {
        return hash('sha256', base64_decode($publicKeyBase64));
    }

    /**
     * Designate a trusted contact.
     *
     * Under E2EE (F1) the owner's client wraps the vault master password with
     * the grantee's RSA public key AT DESIGNATION TIME and submits both the
     * wrapped key and the grantee public-key fingerprint used. When the grantee
     * is not (yet) a registered user, no key can be stored and the contact is
     * marked pending-linkage; the owner must re-save once the grantee registers
     * (the UI surfaces a nudge, and the vault-data endpoint fails closed).
     */
    public function designateContact(User $owner, string $email, int $waitDays, string $accessType, ?string $encryptedKey = null, ?string $granteePublicKeyFingerprint = null) : EmergencyContact
    {
        if ($owner->email === $email) {
            throw new \InvalidArgumentException('You cannot designate yourself as an emergency contact.');
        }

        $count = EmergencyContact::where('owner_id', $owner->id)
            ->whereIn('status', ['pending', 'confirmed', 'active'])
            ->count();

        if ($count >= self::MAX_CONTACTS) {
            throw new \OverflowException('Maximum of ' . self::MAX_CONTACTS . ' emergency contacts allowed.');
        }

        $trustedUser = User::where('email', $email)->first();

        // A wrapped key can only be accepted for a registered grantee whose
        // current public key matches the submitted fingerprint.
        if ($encryptedKey !== null) {
            if (! $trustedUser || empty($trustedUser->public_key)) {
                throw new \InvalidArgumentException('The contact must be a registered user with a public key before an encrypted key can be stored.');
            }

            if (self::publicKeyFingerprint($trustedUser->public_key) !== $granteePublicKeyFingerprint) {
                throw new \InvalidArgumentException('The grantee public key fingerprint does not match their registered key.');
            }
        }

        $contact = EmergencyContact::updateOrCreate(
            ['owner_id' => $owner->id, 'email' => $email],
            [
                'trusted_user_id'                => $trustedUser?->id,
                'wait_days'                      => $waitDays,
                'access_type'                    => $accessType,
                'status'                         => $trustedUser ? 'confirmed' : 'pending',
                'encrypted_key'                  => $encryptedKey,
                'grantee_public_key_fingerprint' => $granteePublicKeyFingerprint,
            ]
        );

        Log::info('Emergency contact designated', ['owner' => $owner->id, 'contact_email' => $email]);

        return $contact;
    }

    public function requestAccess(EmergencyContact $contact) : EmergencyAccessRequest
    {
        if (!in_array($contact->status, ['confirmed', 'active'])) {
            throw new \RuntimeException('This emergency contact is not active.');
        }

        // Dedup: only one pending request per contact (B13).
        $existing = EmergencyAccessRequest::where('contact_id', $contact->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            throw new \RuntimeException('An access request is already pending for this contact.');
        }

        return EmergencyAccessRequest::create([
            'contact_id'   => $contact->id,
            'requester_id' => $contact->trusted_user_id,
            'status'       => 'pending',
            'requested_at' => now(),
        ]);
    }

    public function approveRequest(EmergencyAccessRequest $request, ?string $encryptedKey = null) : void
    {
        // B13: only pending requests can be approved (a denied request must not
        // be re-approved after the fact).
        if ($request->status !== 'pending') {
            throw new \RuntimeException('Only pending requests can be approved.');
        }

        $request->update([
            'status'       => 'approved',
            'responded_at' => now(),
            'granted_at'   => now(),
        ]);

        $request->contact->update(array_filter([
            'status'        => 'active',
            'granted_at'    => now(),
            // Under F1 the key is stored at designation; the approve payload
            // key is accepted only when provided (backwards compatibility).
            'encrypted_key' => $encryptedKey,
        ], fn ($value) => $value !== null));
    }

    public function denyRequest(EmergencyAccessRequest $request) : void
    {
        if ($request->status !== 'pending') {
            throw new \RuntimeException('Only pending requests can be denied.');
        }

        $request->update(['status' => 'denied', 'responded_at' => now()]);
    }

    public function revokeContact(EmergencyContact $contact) : void
    {
        $contact->update([
            'status'                         => 'revoked',
            'encrypted_key'                  => null,
            'grantee_public_key_fingerprint' => null,
            'granted_at'                     => null,
        ]);
        $contact->accessRequests()->where('status', 'pending')->update(['status' => 'denied', 'responded_at' => now()]);

        Log::info('Emergency contact revoked', ['contact_id' => $contact->id]);
    }

    /**
     * Link pending emergency contacts to a freshly registered user (B17).
     *
     * Called on user registration: any pending contact row whose email matches
     * the new user becomes confirmed and is attached to that user. The owner
     * still has to re-save the wrapped key (the UI nudges them).
     */
    public function linkPendingContacts(User $user) : int
    {
        $linked = EmergencyContact::where('email', $user->email)
            ->where('status', 'pending')
            ->update(['trusted_user_id' => $user->id, 'status' => 'confirmed']);

        if ($linked > 0) {
            Log::info('Pending emergency contacts linked to new user', [
                'user_id'  => $user->id,
                'contacts' => $linked,
            ]);
        }

        return $linked;
    }

    /**
     * Whether the contact's stored wrapped key is usable by its grantee.
     *
     * RT3 fail-closed predicate: a key is stale when it is missing, or when
     * the grantee has since rotated/registered a different public key than
     * the one used at wrap time. Pre-existing active rows with a null key are
     * stale by definition.
     */
    public function isKeyStale(EmergencyContact $contact) : bool
    {
        if (blank($contact->encrypted_key) || blank($contact->grantee_public_key_fingerprint)) {
            return true;
        }

        $grantee = $contact->trustedUser;

        if (! $grantee || blank($grantee->public_key)) {
            return true;
        }

        return self::publicKeyFingerprint($grantee->public_key) !== $contact->grantee_public_key_fingerprint;
    }

    /**
     * Auto-grant expired pending requests (scheduled daily).
     */
    public function processExpiredRequests(): int
    {
        $processed = 0;
        EmergencyAccessRequest::where('status', 'pending')
            ->with('contact')
            ->get()
            ->each(function (EmergencyAccessRequest $request) use (&$processed) {
                $waitDays = $request->contact->wait_days;
                if (abs(now()->diffInDays($request->requested_at)) >= $waitDays) {
                    $request->update([
                        'status'    => 'auto_granted',
                        'granted_at'=> now(),
                    ]);
                    $request->contact->update([
                        'status'     => 'active',
                        'granted_at' => now(),
                    ]);
                    $processed++;
                    Log::info('Emergency access auto-granted', ['contact_id' => $request->contact_id]);
                }
            });

        return $processed;
    }

    /**
     * Check all confirmed contacts for owner inactivity (dead man's switch).
     */
    public function checkDeadMansSwitch(): int
    {
        $triggered = 0;
        EmergencyContact::where('status', 'confirmed')
            ->with('owner')
            ->chunk(100, function ($contacts) use (&$triggered) {
                foreach ($contacts as $contact) {
                    $owner       = $contact->owner;
                    $lastSeen    = $owner->last_seen_at ?? $owner->created_at;
                    $inactiveDays = (int) abs(now()->diffInDays($lastSeen));

                    if ($inactiveDays >= $contact->wait_days) {
                        // Auto-create a request and immediately grant it.
                        // The wrapped key was stored at designation time; if it
                        // is stale the vault-data endpoint fails closed (RT3).
                        $request = EmergencyAccessRequest::firstOrCreate(
                            ['contact_id' => $contact->id, 'status' => 'pending'],
                            ['requester_id' => $contact->trusted_user_id, 'requested_at' => now()]
                        );

                        if ($request->wasRecentlyCreated) {
                            $request->update(['status' => 'auto_granted', 'granted_at' => now()]);
                            $contact->update(['status' => 'active', 'granted_at' => now()]);
                            $triggered++;
                            Log::info("Dead man's switch triggered", ['contact_id' => $contact->id, 'owner' => $owner->id]);
                        }
                    }
                }
            });

        return $triggered;
    }
}
