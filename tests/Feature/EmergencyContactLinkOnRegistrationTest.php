<?php

namespace Tests\Feature;

use App\Models\EmergencyContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * B17: pending emergency contacts (designated for an email before the user
 * registered) are linked to the freshly registered user.
 */
#[CoversClass(\App\Http\Controllers\Auth\RegisterController::class)]
#[CoversClass(\App\Services\EmergencyAccessService::class)]
class EmergencyContactLinkOnRegistrationTest extends FeatureTestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_registration_links_pending_emergency_contacts_for_the_email()
    {
        $owner = User::factory()->create();

        $contact = EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'email'    => 'newuser@synthetic.example',
            'status'   => 'pending',
        ]);

        $response = $this->postJson('/user', [
            'name'                 => 'New User',
            'email'                => 'newuser@synthetic.example',
            'password'             => 'supersecret',
            'password_confirmation' => 'supersecret',
        ]);

        $response->assertStatus(201);

        $newUser = User::where('email', 'newuser@synthetic.example')->firstOrFail();

        $this->assertDatabaseHas('emergency_contacts', [
            'id'              => $contact->id,
            'trusted_user_id' => $newUser->id,
            'status'          => 'confirmed',
        ]);
    }

    #[Test]
    public function test_registration_does_not_link_contacts_for_other_emails()
    {
        $owner = User::factory()->create();

        EmergencyContact::factory()->create([
            'owner_id' => $owner->id,
            'email'    => 'other@synthetic.example',
            'status'   => 'pending',
            'trusted_user_id' => null,
        ]);

        $this->postJson('/user', [
            'name'                 => 'New User',
            'email'                => 'newuser@synthetic.example',
            'password'             => 'supersecret',
            'password_confirmation' => 'supersecret',
        ])->assertStatus(201);

        $this->assertDatabaseHas('emergency_contacts', [
            'email'           => 'other@synthetic.example',
            'trusted_user_id' => null,
            'status'          => 'pending',
        ]);
        // unchanged: no trusted_user_id was backfilled for an unrelated email
    }
}
