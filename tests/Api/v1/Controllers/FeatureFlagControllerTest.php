<?php

namespace Tests\Api\v1\Controllers;

use App\Api\v1\Controllers\FeatureFlagController;
use App\Models\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\FeatureTestCase;

/**
 * FeatureFlagController test class
 */
#[CoversClass(FeatureFlagController::class)]
class FeatureFlagControllerTest extends FeatureTestCase
{
    protected function createEncryptedUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'encryption_enabled' => true,
            'encryption_salt' => 'test_salt',
            'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
            'encryption_version' => 1,
            'vault_locked' => false,
        ], $attributes));
    }

    #[Test]
    public function test_unauthenticated_access_is_denied()
    {
        $this->json('GET', '/api/v1/features')
            ->assertStatus(401);
    }

    #[Test]
    public function test_index_returns_all_features()
    {
        $user = $this->createEncryptedUser();
        $features = config('2fauth.features');

        $response = $this->actingAs($user, 'api-guard')
            ->json('GET', '/api/v1/features')
            ->assertOk()
            ->assertJsonCount(count($features));

        foreach ($features as $feature) {
            $response->assertJsonFragment([
                'name' => $feature,
                'state' => 'enabled',
            ]);
        }
    }

    #[Test]
    public function test_show_existing_feature_returns_enabled()
    {
        $user = $this->createEncryptedUser();
        $existingFeature = config('2fauth.features')[0];

        $this->actingAs($user, 'api-guard')
            ->json('GET', '/api/v1/features/' . $existingFeature)
            ->assertOk()
            ->assertJson([
                'name' => $existingFeature,
                'state' => 'enabled'
            ]);
    }

    #[Test]
    public function test_show_unknown_feature_returns_disabled()
    {
        $user = $this->createEncryptedUser();
        $this->actingAs($user, 'api-guard')
            ->json('GET', '/api/v1/features/unknownFeature')
            ->assertStatus(404)
            ->assertJson(['message' => 'not found']);
    }
}
