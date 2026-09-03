<?php

namespace Tests\Api\v1;

use App\Facades\Settings;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * Recovery codes (external-service backup codes) storage.
 *
 * Codes are reversible (the user must view them later), so they follow the
 * `notes` encrypted-text pattern rather than one-way hashing. Under the
 * non-E2EE path the server encrypts via Laravel Crypt; the stored column must
 * never contain plaintext.
 */
class TwoFAccountRecoveryCodesTest extends FeatureTestCase
{
    /**
     * @var \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable
     */
    protected $user;

    protected function setUp() : void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    private function storePayload(array $override = []) : array
    {
        return array_merge([
            'service'        => 'GitHub',
            'account'        => 'octocat',
            'otp_type'       => 'totp',
            'secret'         => 'A4GRFHVVRBGY7UIW',
            'digits'         => 6,
            'algorithm'      => 'sha1',
            'period'         => 30,
            'recovery_codes' => '["abcd-1234","efgh-5678"]',
        ], $override);
    }

    #[Test]
    public function store_persists_and_returns_recovery_codes_encrypted_at_rest() : void
    {
        // Enable server-side encryption so codes are ciphertext at rest (not plaintext).
        Settings::set('useEncryption', true);

        $codes = '["abcd-1234","efgh-5678"]';

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $response = $this
            ->json('POST', '/api/v1/twofaccounts', $this->storePayload(['recovery_codes' => $codes]))
            ->assertCreated();

        // API returns the decrypted value
        $response->assertJsonFragment(['recovery_codes' => $codes]);

        $id = $response->json('id');

        // Stored column must be ciphertext, never plaintext
        $stored = DB::table('twofaccounts')->where('id', $id)->value('recovery_codes');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('abcd-1234', $stored);
    }

    #[Test]
    public function show_returns_recovery_codes() : void
    {
        $account = TwoFAccount::factory()->for($this->user)->create();
        $account->forceFill(['recovery_codes' => '["code-one"]'])->save();

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/twofaccounts/' . $account->id . '?withSecret=1')
            ->assertOk()
            ->assertJsonFragment(['recovery_codes' => '["code-one"]']);
    }

    #[Test]
    public function update_without_recovery_codes_preserves_existing_value() : void
    {
        $account = TwoFAccount::factory()->for($this->user)->create();
        $account->forceFill(['recovery_codes' => '["keep-me"]'])->save();

        // Update with all required fields but omit recovery_codes
        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('PATCH', '/api/v1/twofaccounts/' . $account->id, [
                'service'   => 'GitHub',
                'account'   => 'octocat',
                'icon'      => null,
                'otp_type'  => 'totp',
                'secret'    => 'A4GRFHVVRBGY7UIW',
                'digits'    => 6,
                'algorithm' => 'sha1',
                'period'    => 30,
            ])
            ->assertOk()
            ->assertJsonFragment(['recovery_codes' => '["keep-me"]']);
    }

    #[Test]
    public function update_with_empty_clears_recovery_codes() : void
    {
        $account = TwoFAccount::factory()->for($this->user)->create();
        $account->forceFill(['recovery_codes' => '["clear-me"]'])->save();

        Passport::actingAs($this->user, ['legacy_full_access'], 'api-guard');
        $this
            ->json('PATCH', '/api/v1/twofaccounts/' . $account->id, [
                'service'        => 'GitHub',
                'account'        => 'octocat',
                'icon'           => null,
                'otp_type'       => 'totp',
                'secret'         => 'A4GRFHVVRBGY7UIW',
                'digits'         => 6,
                'algorithm'      => 'sha1',
                'period'         => 30,
                'recovery_codes' => null,
            ])
            ->assertOk()
            ->assertJsonFragment(['recovery_codes' => null]);
    }

    #[Test]
    public function other_user_cannot_read_recovery_codes() : void
    {
        $owner    = User::factory()->create();
        $intruder = User::factory()->create();
        $account  = TwoFAccount::factory()->for($owner)->create();
        $account->forceFill(['recovery_codes' => '["secret-code"]'])->save();

        Passport::actingAs($intruder, ['legacy_full_access'], 'api-guard');
        $this
            ->json('GET', '/api/v1/twofaccounts/' . $account->id)
            ->assertForbidden();
    }
}
