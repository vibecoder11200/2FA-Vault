<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create([
            'email' => 'test@example.com',
        ]);
    }

    /** @test */
    public function test_user_can_store_subscription()
    {
        $subscriptionData = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-endpoint-id',
            'p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib37gp_rvQ...',
            'auth' => 'tBHItJI5svbpez7KI4CCXg==',
        ];

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', $subscriptionData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'id',
                'endpoint',
                'created_at',
            ]);

        // Verify subscription was stored in database
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'endpoint' => $subscriptionData['endpoint'],
            'p256dh' => $subscriptionData['p256dh'],
            'auth' => $subscriptionData['auth'],
        ]);

        // Verify user relationship
        $this->assertCount(1, $this->user->pushSubscriptions);
    }

    /** @test */
    public function test_store_subscription_requires_authentication()
    {
        $subscriptionData = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-endpoint-id',
            'p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib37gp_rvQ...',
            'auth' => 'tBHItJI5svbpez7KI4CCXg==',
        ];

        $response = $this->postJson('/api/v1/push/subscribe', $subscriptionData);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_store_subscription_requires_endpoint()
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', [
                'p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib37gp_rvQ...',
                'auth' => 'tBHItJI5svbpez7KI4CCXg==',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint']);
    }

    /** @test */
    public function test_store_subscription_endpoint_must_be_url()
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'not-a-valid-url',
                'p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib37gp_rvQ...',
                'auth' => 'tBHItJI5svbpez7KI4CCXg==',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint']);
    }

    /** @test */
    public function test_store_subscription_requires_public_key()
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-endpoint-id',
                'auth' => 'tBHItJI5svbpez7KI4CCXg==',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['p256dh']);
    }

    /** @test */
    public function test_store_subscription_requires_auth_token()
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-endpoint-id',
                'p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib37gp_rvQ...',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['auth']);
    }

    /** @test */
    public function test_duplicate_subscription_updates_existing()
    {
        $subscriptionData = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-endpoint-id',
            'p256dh' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib37gp_rvQ...',
            'auth' => 'tBHItJI5svbpez7KI4CCXg==',
        ];

        // Store first subscription
        $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', $subscriptionData);

        // Store same subscription again with updated keys
        $updatedData = [
            'endpoint' => $subscriptionData['endpoint'],
            'p256dh' => 'NewPublicKey123...',
            'auth' => 'NewAuthToken123==',
        ];

        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', $updatedData);

        $response->assertStatus(201);

        // Verify only one subscription exists
        $this->assertCount(1, PushSubscription::all());

        // Verify subscription was updated
        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->user->id,
            'endpoint' => $updatedData['endpoint'],
            'p256dh' => $updatedData['p256dh'],
            'auth' => $updatedData['auth'],
        ]);
    }

    /** @test */
    public function test_user_can_have_multiple_subscriptions()
    {
        $subscription1 = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/endpoint-1',
            'p256dh' => 'PublicKey1...',
            'auth' => 'AuthToken1==',
        ];

        $subscription2 = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/endpoint-2',
            'p256dh' => 'PublicKey2...',
            'auth' => 'AuthToken2==',
        ];

        $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', $subscription1);

        $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/push/subscribe', $subscription2);

        // Verify both subscriptions exist
        $this->assertCount(2, $this->user->pushSubscriptions);
    }

    /** @test */
    public function test_user_can_remove_subscription()
    {
        // Create subscription first
        $subscription = PushSubscription::factory()->create([
            'user_id' => $this->user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-endpoint-id',
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->deleteJson('/api/v1/push/unsubscribe', [
                'endpoint' => $subscription->endpoint,
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Subscription removed successfully',
            ]);

        // Verify subscription was deleted
        $this->assertDatabaseMissing('push_subscriptions', [
            'id' => $subscription->id,
        ]);

        $this->assertCount(0, $this->user->pushSubscriptions);
    }

    /** @test */
    public function test_remove_subscription_requires_authentication()
    {
        $response = $this->deleteJson('/api/v1/push/unsubscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/example-endpoint-id',
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_remove_subscription_requires_endpoint()
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->deleteJson('/api/v1/push/unsubscribe', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['endpoint']);
    }

    /** @test */
    public function test_remove_nonexistent_subscription_returns_404()
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->deleteJson('/api/v1/push/unsubscribe', [
                'endpoint' => 'https://fcm.googleapis.com/fcm/send/nonexistent-endpoint',
            ]);

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Subscription not found',
            ]);
    }

    /** @test */
    public function test_user_cannot_remove_another_users_subscription()
    {
        $otherUser = User::factory()->create();
        
        $otherSubscription = PushSubscription::factory()->create([
            'user_id' => $otherUser->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/other-user-endpoint',
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->deleteJson('/api/v1/push/unsubscribe', [
                'endpoint' => $otherSubscription->endpoint,
            ]);

        $response->assertStatus(404);

        // Verify other user's subscription still exists
        $this->assertDatabaseHas('push_subscriptions', [
            'id' => $otherSubscription->id,
        ]);
    }

    /** @test */
    public function test_user_can_list_their_subscriptions()
    {
        PushSubscription::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->getJson('/api/v1/push/subscriptions');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'endpoint',
                        'created_at',
                    ],
                ],
            ]);
    }

    /** @test */
    public function test_list_subscriptions_only_shows_current_users()
    {
        // Create subscriptions for current user
        PushSubscription::factory()->count(2)->create([
            'user_id' => $this->user->id,
        ]);

        // Create subscription for another user
        $otherUser = User::factory()->create();
        PushSubscription::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->user, 'api-guard')
            ->getJson('/api/v1/push/subscriptions');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    /** @test */
    public function test_subscriptions_deleted_when_user_deleted()
    {
        PushSubscription::factory()->count(3)->create([
            'user_id' => $this->user->id,
        ]);

        $this->user->delete();

        // Verify all subscriptions were deleted
        $this->assertCount(0, PushSubscription::all());
    }
}
