<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * UserInvitationRegistrationTest test class
 */
#[CoversClass(\App\Http\Controllers\Auth\RegisterController::class)]
class UserInvitationRegistrationTest extends FeatureTestCase
{
    use RefreshDatabase;

    private function validPayload(string $email = 'newuser@synthetic.example'): array
    {
        return [
            'name' => 'New User',
            'email' => $email,
            'password' => 'supersecret',
            'password_confirmation' => 'supersecret',
        ];
    }

    #[Test]
    public function test_can_register_with_a_valid_invitation_token()
    {
        $admin = User::factory()->administrator()->create();
        $invitation = UserInvitation::factory()->create([
            'invited_by_id' => $admin->id,
            'email' => 'invited@synthetic.example',
        ]);

        $response = $this->postJson('/user', array_merge($this->validPayload('invited@synthetic.example'), [
            'invitation' => $invitation->token,
        ]));

        $response->assertStatus(201);

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertDatabaseHas('users', ['email' => 'invited@synthetic.example']);
    }

    #[Test]
    public function test_cannot_register_with_an_expired_invitation()
    {
        $invitation = UserInvitation::factory()->expired()->create();

        $response = $this->postJson('/user', array_merge($this->validPayload(), [
            'invitation' => $invitation->token,
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'newuser@synthetic.example']);
    }

    #[Test]
    public function test_cannot_register_with_an_already_accepted_invitation()
    {
        $invitation = UserInvitation::factory()->accepted()->create();

        $response = $this->postJson('/user', array_merge($this->validPayload(), [
            'invitation' => $invitation->token,
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'newuser@synthetic.example']);
    }

    #[Test]
    public function test_cannot_register_with_an_unknown_invitation_token()
    {
        $response = $this->postJson('/user', array_merge($this->validPayload(), [
            'invitation' => 'this-token-does-not-exist',
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'newuser@synthetic.example']);
    }

    #[Test]
    public function test_cannot_register_with_a_valid_invitation_under_a_mismatched_email()
    {
        // A13: the invitation is bound to the invited email - a link holder
        // must not consume it by registering under any other address.
        $admin = User::factory()->administrator()->create();
        $invitation = UserInvitation::factory()->create([
            'invited_by_id' => $admin->id,
            'email' => 'invited@synthetic.example',
        ]);

        $response = $this->postJson('/user', array_merge($this->validPayload('intruder@synthetic.example'), [
            'invitation' => $invitation->token,
        ]));

        $response->assertStatus(422);

        // Invitation NOT consumed, no user created.
        $invitation->refresh();
        $this->assertNull($invitation->accepted_at);
        $this->assertDatabaseMissing('users', ['email' => 'intruder@synthetic.example']);
        $this->assertDatabaseMissing('users', ['email' => 'invited@synthetic.example']);
    }
}
