<?php

namespace Tests\Api\v1;

use App\Mail\UserInvitationMail;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * InvitationTest test class
 */
#[CoversClass(\App\Api\v1\Controllers\InvitationController::class)]
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_admin_can_create_invitation_and_mail_is_sent()
    {
        Mail::fake();

        $admin = User::factory()->administrator()->create();

        Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/user/invitations', [
            'email' => 'invitee@synthetic.example',
            'role'  => 'user',
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['email' => 'invitee@synthetic.example']);

        $this->assertDatabaseHas('user_invitations', [
            'email'         => 'invitee@synthetic.example',
            'invited_by_id' => $admin->id,
        ]);

        Mail::assertSent(UserInvitationMail::class, fn ($m) => $m->hasTo('invitee@synthetic.example'));
    }

    #[Test]
    public function test_non_admin_cannot_create_invitation()
    {
        $user = User::factory()->create();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/user/invitations', [
            'email' => 'invitee@synthetic.example',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('user_invitations', ['email' => 'invitee@synthetic.example']);
    }

    #[Test]
    public function test_admin_can_list_pending_invitations()
    {
        $admin = User::factory()->administrator()->create();
        UserInvitation::factory()->create();      // pending
        UserInvitation::factory()->accepted()->create();
        UserInvitation::factory()->expired()->create();

        Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/user/invitations');

        // Non-paginated resource collection → a bare JSON array of pending invitations.
        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    #[Test]
    public function test_admin_can_revoke_invitation()
    {
        $admin      = User::factory()->administrator()->create();
        $invitation = UserInvitation::factory()->create();

        Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');
        $response = $this->deleteJson('/api/v1/user/invitations/' . $invitation->id);

        $response->assertStatus(204);
        $this->assertDatabaseMissing('user_invitations', ['id' => $invitation->id]);
    }

    #[Test]
    public function test_admin_cannot_invite_an_already_registered_email()
    {
        Mail::fake();
        $admin    = User::factory()->administrator()->create();
        $existing = User::factory()->create();

        Passport::actingAs($admin, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/user/invitations', [
            'email' => $existing->email,
        ]);

        $response->assertStatus(422);
    }
}
