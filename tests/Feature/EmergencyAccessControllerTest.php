<?php

namespace Tests\Feature;

use App\Models\EmergencyAccessRequest;
use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\TestCase;

class EmergencyAccessControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function createEncryptedUser(array $attributes = []) : User
    {
        return User::factory()->create(array_merge([
            'encryption_enabled'    => true,
            'encryption_salt'       => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version'    => 1,
            'vault_locked'          => false,
        ], $attributes));
    }

    public function test_user_can_list_contacts() : void
    {
        $owner = $this->createEncryptedUser();

        EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'confirmed',
        ]);
        EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'active',
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson('/api/v1/emergency-contacts');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_user_can_designate_contact() : void
    {
        $owner   = $this->createEncryptedUser();
        $trusted = $this->createEncryptedUser(['email' => 'trusted@example.com']);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/emergency-contacts', [
                'email'       => 'trusted@example.com',
                'wait_days'   => 30,
                'access_type' => 'view_only',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'email'       => 'trusted@example.com',
                'access_type' => 'view_only',
            ]);

        $this->assertDatabaseHas('emergency_contacts', [
            'owner_id' => $owner->id,
            'email'    => 'trusted@example.com',
            'status'   => 'confirmed',
        ]);
    }

    public function test_returns_422_on_6th_contact() : void
    {
        $owner = $this->createEncryptedUser();

        // Create 5 existing contacts
        for ($i = 0; $i < 5; $i++) {
            EmergencyContact::factory()->create([
                'owner_id' => $owner->id,
                'email'    => "contact{$i}@example.com",
                'status'   => 'confirmed',
            ]);
        }

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/emergency-contacts', [
                'email'       => 'sixth@example.com',
                'wait_days'   => 30,
                'access_type' => 'view_only',
            ]);

        $response->assertStatus(422);
    }

    public function test_user_can_revoke_contact() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'confirmed',
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->deleteJson("/api/v1/emergency-contacts/{$contact->id}");

        $response->assertStatus(204);

        $this->assertDatabaseHas('emergency_contacts', [
            'id'     => $contact->id,
            'status' => 'revoked',
        ]);
    }

    public function test_grantee_can_request_access() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        $contact = EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'confirmed',
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson("/api/v1/emergency-contacts/{$contact->id}/request");

        $response->assertStatus(201)
            ->assertJsonFragment([
                'contact_id' => $contact->id,
                'status'     => 'pending',
            ]);
    }

    public function test_owner_can_approve_request() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'status'   => 'confirmed',
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'pending',
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson("/api/v1/emergency-requests/{$request->id}/approve", [
                'encrypted_key' => 'aes-256-gcm-key-data',
            ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Access approved']);

        $this->assertDatabaseHas('emergency_access_requests', [
            'id'     => $request->id,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('emergency_contacts', [
            'id'     => $contact->id,
            'status' => 'active',
        ]);
    }

    public function test_owner_can_deny_request() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'pending',
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson("/api/v1/emergency-requests/{$request->id}/deny");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Access denied']);

        $this->assertDatabaseHas('emergency_access_requests', [
            'id'     => $request->id,
            'status' => 'denied',
        ]);
    }

    public function test_owner_can_list_pending_requests() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
        ]);

        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'pending',
        ]);
        EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'approved',
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson('/api/v1/emergency-requests/pending');

        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_user_can_list_contacts_where_they_are_trusted() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'confirmed',
        ]);
        EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'active',
        ]);
        // Revoked contact should not appear
        EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'revoked',
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson('/api/v1/emergency-contacts/for-me');

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_unauthorized_user_cannot_access_contacts() : void
    {
        $response = $this->getJson('/api/v1/emergency-contacts');

        $response->assertStatus(401);
    }

    // ── F1 / RT3: end-to-end emergency access ─────────────────────────────

    protected function granteePublicKey() : string
    {
        // A fake but structurally valid base64 SPKI blob; only the
        // fingerprint consistency matters for these tests.
        return base64_encode(random_bytes(128));
    }

    public function test_designation_stores_encrypted_key_and_fingerprint() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser(['public_key' => $publicKey = $this->granteePublicKey()]);

        $fingerprint = \App\Services\EmergencyAccessService::publicKeyFingerprint($publicKey);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/emergency-contacts', [
                'email'                          => $grantee->email,
                'wait_days'                      => 30,
                'access_type'                    => 'view_only',
                'encrypted_key'                  => 'wrapped-vault-key',
                'grantee_public_key_fingerprint' => $fingerprint,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('emergency_contacts', [
            'owner_id'                       => $owner->id,
            'trusted_user_id'                => $grantee->id,
            'encrypted_key'                  => 'wrapped-vault-key',
            'grantee_public_key_fingerprint' => $fingerprint,
        ]);
    }

    public function test_designation_rejects_mismatched_fingerprint() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser(['public_key' => $this->granteePublicKey()]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->postJson('/api/v1/emergency-contacts', [
                'email'                          => $grantee->email,
                'wait_days'                      => 30,
                'access_type'                    => 'view_only',
                'encrypted_key'                  => 'wrapped-vault-key',
                'grantee_public_key_fingerprint' => str_repeat('a', 64),
            ]);

        $response->assertStatus(422);
    }

    public function test_grantee_key_info_lookup() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser(['public_key' => $publicKey = $this->granteePublicKey()]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');

        $this->getJson('/api/v1/emergency-contacts/grantee-key-info?email=' . $grantee->email)
            ->assertStatus(200)
            ->assertJsonFragment([
                'user_id'     => $grantee->id,
                'public_key'  => $publicKey,
                'fingerprint' => \App\Services\EmergencyAccessService::publicKeyFingerprint($publicKey),
            ]);

        $this->getJson('/api/v1/emergency-contacts/grantee-key-info?email=unknown@example.com')
            ->assertStatus(404);
    }

    public function test_grantee_can_read_vault_data_after_access_granted() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser(['public_key' => $publicKey = $this->granteePublicKey()]);

        $account = \App\Models\TwoFAccount::factory()->create([
            'user_id'   => $owner->id,
            'encrypted' => true,
            'secret'    => json_encode(['ciphertext' => base64_encode('c'), 'iv' => base64_encode('i'), 'authTag' => base64_encode('t')]),
        ]);

        $contact = EmergencyContact::factory()->create([
            'owner_id'                       => $owner->id,
            'trusted_user_id'                => $grantee->id,
            'status'                         => 'active',
            'encrypted_key'                  => 'wrapped-vault-key',
            'grantee_public_key_fingerprint' => \App\Services\EmergencyAccessService::publicKeyFingerprint($publicKey),
            'granted_at'                     => now(),
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/emergency-contacts/{$contact->id}/vault-data");

        $response->assertStatus(200)
            ->assertJsonFragment([
                'encrypted_key' => 'wrapped-vault-key',
                'access_type'   => $contact->access_type,
            ])
            ->assertJsonFragment([
                'id'      => $account->id,
                'service' => $account->service,
            ]);
    }

    public function test_vault_data_fails_closed_when_grantee_key_rotated() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser(['public_key' => $this->granteePublicKey()]);

        $contact = EmergencyContact::factory()->create([
            'owner_id'                       => $owner->id,
            'trusted_user_id'                => $grantee->id,
            'status'                         => 'active',
            'encrypted_key'                  => 'wrapped-vault-key',
            'grantee_public_key_fingerprint' => str_repeat('b', 64), // stale
            'granted_at'                     => now(),
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/emergency-contacts/{$contact->id}/vault-data");

        $response->assertStatus(409)
            ->assertJsonFragment(['error' => 'emergency_key_unavailable']);
    }

    public function test_vault_data_fails_closed_for_stale_active_row_without_key() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        // Pre-F1 shape: auto-grant flipped the status but no key was stored.
        $contact = EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'active',
            'encrypted_key'   => null,
            'granted_at'      => now(),
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson("/api/v1/emergency-contacts/{$contact->id}/vault-data");

        $response->assertStatus(409)
            ->assertJsonFragment(['error' => 'emergency_key_unavailable']);
    }

    public function test_non_grantee_cannot_read_vault_data() : void
    {
        $owner    = $this->createEncryptedUser();
        $grantee  = $this->createEncryptedUser(['public_key' => $publicKey = $this->granteePublicKey()]);
        $stranger = $this->createEncryptedUser();

        $contact = EmergencyContact::factory()->create([
            'owner_id'                       => $owner->id,
            'trusted_user_id'                => $grantee->id,
            'status'                         => 'active',
            'encrypted_key'                  => 'wrapped-vault-key',
            'grantee_public_key_fingerprint' => \App\Services\EmergencyAccessService::publicKeyFingerprint($publicKey),
        ]);

        Passport::actingAs($stranger, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson("/api/v1/emergency-contacts/{$contact->id}/vault-data")
            ->assertStatus(403);
    }

    public function test_vault_data_requires_active_status() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        $contact = EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'confirmed',
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson("/api/v1/emergency-contacts/{$contact->id}/vault-data")
            ->assertStatus(409);
    }

    public function test_contacts_for_me_exposes_encrypted_key_only_when_active() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        $activeContact = EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'active',
            'encrypted_key'   => 'wrapped-vault-key',
        ]);
        $confirmedContact = EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'confirmed',
            'encrypted_key'   => 'wrapped-vault-key',
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->getJson('/api/v1/emergency-contacts/for-me');

        $response->assertStatus(200);

        $active    = collect($response->json())->firstWhere('id', $activeContact->id);
        $confirmed = collect($response->json())->firstWhere('id', $confirmedContact->id);

        $this->assertSame('wrapped-vault-key', $active['encrypted_key']);
        $this->assertArrayNotHasKey('encrypted_key', $confirmed);
        $this->assertTrue($active['has_encrypted_key']);
    }

    // ── B13 guards ────────────────────────────────────────────────────────

    public function test_approve_requires_pending_status() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'denied',
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson("/api/v1/emergency-requests/{$request->id}/approve")
            ->assertStatus(422);

        $this->assertDatabaseHas('emergency_access_requests', [
            'id'     => $request->id,
            'status' => 'denied',
        ]);
    }

    public function test_deny_requires_pending_status() : void
    {
        $owner   = $this->createEncryptedUser();
        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
        ]);

        $request = EmergencyAccessRequest::factory()->create([
            'contact_id' => $contact->id,
            'status'     => 'approved',
        ]);

        Passport::actingAs($owner, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson("/api/v1/emergency-requests/{$request->id}/deny")
            ->assertStatus(422);
    }

    public function test_request_access_is_deduplicated() : void
    {
        $owner   = $this->createEncryptedUser();
        $grantee = $this->createEncryptedUser();

        $contact = EmergencyContact::factory()->create([
            'owner_id'        => $owner->id,
            'trusted_user_id' => $grantee->id,
            'status'          => 'confirmed',
        ]);

        Passport::actingAs($grantee, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson("/api/v1/emergency-contacts/{$contact->id}/request")
            ->assertStatus(201);

        $this
            ->postJson("/api/v1/emergency-contacts/{$contact->id}/request")
            ->assertStatus(422);

        $this->assertSame(1, EmergencyAccessRequest::where('contact_id', $contact->id)->where('status', 'pending')->count());
    }
}
