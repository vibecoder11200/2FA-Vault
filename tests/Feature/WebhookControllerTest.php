<?php

namespace Tests\Feature;

use App\Enums\WebhookEvent;
use App\Jobs\WebhookDeliveryJob;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Passport\Passport;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
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

    private function createWebhookForUser(User $user, array $overrides = []) : Webhook
    {
        return Webhook::create(array_merge([
            'user_id'   => $user->id,
            'name'      => 'Test Hook',
            'url'       => 'https://example.com/webhook',
            'events'    => ['account.created'],
            'is_active' => true,
        ], $overrides));
    }

    public function test_user_can_list_webhooks() : void
    {
        $user  = $this->createEncryptedUser();
        $hook1 = $this->createWebhookForUser($user, ['name' => 'Hook A']);
        $hook2 = $this->createWebhookForUser($user, ['name' => 'Hook B']);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/webhooks');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Hook A'])
            ->assertJsonFragment(['name' => 'Hook B']);
    }

    public function test_user_can_create_webhook() : void
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/webhooks', [
            'name'   => 'New Hook',
            'url'    => 'https://example.com/new',
            'events' => ['account.created', 'vault.locked'],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['name' => 'New Hook']);

        $this->assertDatabaseHas('webhooks', [
            'user_id' => $user->id,
            'name'    => 'New Hook',
            'url'     => 'https://example.com/new',
        ]);
    }

    public function test_cannot_create_webhook_with_invalid_url() : void
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/webhooks', [
            'name'   => 'Bad Url Hook',
            'url'    => 'not-a-valid-url',
            'events' => ['account.created'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    /**
     * SSRF guard: webhook URLs targeting internal/private ranges must be
     * rejected at registration time.
     */
    public function test_cannot_create_webhook_pointing_to_internal_address() : void
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/webhooks', [
            'name'   => 'Internal Hook',
            'url'    => 'http://169.254.169.254/latest/meta-data',
            'events' => ['account.created'],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    /**
     * SSRF guard: loopback and RFC1918 ranges must also be rejected on update.
     */
    public function test_cannot_update_webhook_to_private_address() : void
    {
        $user    = $this->createEncryptedUser();
        $webhook = $this->createWebhookForUser($user);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->putJson("/api/v1/webhooks/{$webhook->id}", [
            'url' => 'http://10.0.0.5/internal',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }

    public function test_cannot_create_webhook_with_unknown_event() : void
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson('/api/v1/webhooks', [
            'name'   => 'Bad Event Hook',
            'url'    => 'https://example.com/hook',
            'events' => ['account.created', 'fake.event'],
        ]);

        // Controller filters out invalid events; if none remain, returns 422
        $response->assertStatus(201);

        // Only the valid event should have been stored
        $this->assertDatabaseHas('webhooks', [
            'user_id' => $user->id,
            'name'    => 'Bad Event Hook',
        ]);

        $webhook = Webhook::where('name', 'Bad Event Hook')->first();
        $this->assertContains('account.created', $webhook->events);
        $this->assertNotContains('fake.event', $webhook->events);
    }

    public function test_user_can_update_webhook() : void
    {
        $user    = $this->createEncryptedUser();
        $webhook = $this->createWebhookForUser($user);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->putJson("/api/v1/webhooks/{$webhook->id}", [
            'name'      => 'Updated Hook',
            'url'       => 'https://example.com/updated',
            'is_active' => false,
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'Updated Hook']);

        $this->assertDatabaseHas('webhooks', [
            'id'        => $webhook->id,
            'name'      => 'Updated Hook',
            'is_active' => 0,
        ]);
    }

    public function test_user_can_delete_webhook() : void
    {
        $user    = $this->createEncryptedUser();
        $webhook = $this->createWebhookForUser($user);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->deleteJson("/api/v1/webhooks/{$webhook->id}");

        $response->assertStatus(204);
        $this->assertDatabaseMissing('webhooks', ['id' => $webhook->id]);
    }

    public function test_user_can_test_webhook() : void
    {
        Queue::fake();

        $user    = $this->createEncryptedUser();
        $webhook = $this->createWebhookForUser($user);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->postJson("/api/v1/webhooks/{$webhook->id}/test");

        $response->assertStatus(200)
            ->assertJsonFragment(['message' => 'Test delivery queued.']);

        Queue::assertPushed(WebhookDeliveryJob::class, 1);
    }

    public function test_user_can_list_deliveries() : void
    {
        $user    = $this->createEncryptedUser();
        $webhook = $this->createWebhookForUser($user);

        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event'      => 'account.created',
            'payload'    => ['account_id' => 42],
            'attempt'    => 1,
        ]);
        WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event'      => 'account.updated',
            'payload'    => ['account_id' => 99],
            'attempt'    => 1,
        ]);

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson("/api/v1/webhooks/{$webhook->id}/deliveries");

        $response->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function test_webhook_ownership_enforced() : void
    {
        $owner   = $this->createEncryptedUser();
        $other   = $this->createEncryptedUser();
        $webhook = $this->createWebhookForUser($owner);

        // Other user cannot view owner's webhook deliveries
        Passport::actingAs($other, ['legacy_full_access'], 'api-guard');
        $this
            ->getJson("/api/v1/webhooks/{$webhook->id}/deliveries")
            ->assertStatus(404);

        // Other user cannot update
        Passport::actingAs($other, ['legacy_full_access'], 'api-guard');
        $this
            ->putJson("/api/v1/webhooks/{$webhook->id}", ['name' => 'Hacked'])
            ->assertStatus(404);

        // Other user cannot delete
        Passport::actingAs($other, ['legacy_full_access'], 'api-guard');
        $this
            ->deleteJson("/api/v1/webhooks/{$webhook->id}")
            ->assertStatus(404);

        // Other user cannot test
        Passport::actingAs($other, ['legacy_full_access'], 'api-guard');
        $this
            ->postJson("/api/v1/webhooks/{$webhook->id}/test")
            ->assertStatus(404);
    }

    public function test_available_events_endpoint() : void
    {
        $user = $this->createEncryptedUser();

        Passport::actingAs($user, ['legacy_full_access'], 'api-guard');
        $response = $this->getJson('/api/v1/webhooks/events');

        $response->assertStatus(200);

        $events = $response->json();
        $values = array_column($events, 'value');

        // Spot-check a few known events
        $this->assertContains('account.created', $values);
        $this->assertContains('vault.locked', $values);
        $this->assertContains('team.member_joined', $values);
        $this->assertContains('auth.user_login', $values);

        // Ensure total count matches enum cases
        $this->assertCount(count(WebhookEvent::cases()), $events);
    }
}
