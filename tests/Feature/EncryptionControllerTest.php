<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2EE Encryption Controller Tests
 * 
 * Tests for encryption setup, vault locking/unlocking, and verification
 */
class EncryptionControllerTest extends TestCase
{
    use RefreshDatabase;
    
    protected User $user;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
    }
    
    /**
     * Test encryption setup endpoint
     */
    public function test_user_can_setup_encryption(): void
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'test_salt_base64_encoded',
                'encryption_test_value' => '{"ciphertext":"test","iv":"test","authTag":"test"}',
                'encryption_version' => 1
            ]);
        
        $response->assertOk()
            ->assertJson([
                'encryption_enabled' => true
            ]);
        
        // Verify data was stored
        $this->user->refresh();
        $this->assertEquals('test_salt_base64_encoded', $this->user->encryption_salt);
        $this->assertEquals('{"ciphertext":"test","iv":"test","authTag":"test"}', $this->user->encryption_test_value);
        $this->assertEquals(1, $this->user->encryption_version);
        $this->assertFalse($this->user->vault_locked);
    }
    
    /**
     * Test that setup requires authentication
     */
    public function test_encryption_setup_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/encryption/setup', [
            'encryption_salt' => 'test_salt',
            'encryption_test_value' => 'test_value',
            'encryption_version' => 1
        ]);
        
        $response->assertUnauthorized();
    }
    
    /**
     * Test that setup validates required fields
     */
    public function test_encryption_setup_validates_fields(): void
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/setup', [
                // Missing required fields
            ]);
        
        $response->assertStatus(422);
    }
    
    /**
     * Test that setup cannot be done twice
     */
    public function test_encryption_setup_cannot_be_done_twice(): void
    {
        // First setup
        $this->user->encryption_version = 1;
        $this->user->save();
        
        // Try to setup again
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/setup', [
                'encryption_salt' => 'new_salt',
                'encryption_test_value' => 'new_value',
                'encryption_version' => 1
            ]);
        
        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Encryption is already enabled for this account'
            ]);
    }
    
    /**
     * Test getting encryption info
     */
    public function test_user_can_get_encryption_info(): void
    {
        // Setup encryption
        $this->user->encryption_salt = 'test_salt';
        $this->user->encryption_test_value = 'test_value';
        $this->user->encryption_version = 1;
        $this->user->vault_locked = false;
        $this->user->save();
        
        $response = $this->actingAs($this->user, 'api-guard')
            ->getJson('/api/v1/encryption/info');
        
        $response->assertOk()
            ->assertJson([
                'encryption_enabled' => true,
                'encryption_salt' => 'test_salt',
                'encryption_test_value' => 'test_value',
                'encryption_version' => 1,
                'vault_locked' => false
            ]);
    }
    
    /**
     * Test that encryption info returns false for users without encryption
     */
    public function test_encryption_info_returns_false_when_not_enabled(): void
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->getJson('/api/v1/encryption/info');
        
        $response->assertOk()
            ->assertJson([
                'encryption_enabled' => false
            ]);
    }
    
    /**
     * Test vault locking
     */
    public function test_user_can_lock_vault(): void
    {
        // Setup encryption
        $this->user->encryption_version = 1;
        $this->user->vault_locked = false;
        $this->user->save();
        
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/lock');
        
        $response->assertOk()
            ->assertJson([
                'vault_locked' => true
            ]);
        
        $this->user->refresh();
        $this->assertTrue($this->user->vault_locked);
    }
    
    /**
     * Test that locking requires encryption to be enabled
     */
    public function test_locking_requires_encryption_enabled(): void
    {
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/lock');
        
        $response->assertStatus(400);
    }
    
    /**
     * Test password verification endpoint
     */
    public function test_vault_verification(): void
    {
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();
        
        // Test successful verification
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/verify', [
                'verification_result' => true
            ]);
        
        $response->assertOk()
            ->assertJson([
                'vault_locked' => false
            ]);
        
        $this->user->refresh();
        $this->assertFalse($this->user->vault_locked);
    }
    
    /**
     * Test failed verification
     */
    public function test_failed_verification(): void
    {
        $this->user->encryption_version = 1;
        $this->user->vault_locked = true;
        $this->user->save();
        
        $response = $this->actingAs($this->user, 'api-guard')
            ->postJson('/api/v1/encryption/verify', [
                'verification_result' => false
            ]);
        
        $response->assertStatus(401);
        
        $this->user->refresh();
        $this->assertTrue($this->user->vault_locked);
    }
    
    /**
     * Test rate limiting on setup endpoint
     */
    public function test_setup_endpoint_is_rate_limited(): void
    {
        
        // Make multiple requests quickly
        for ($i = 0; $i < 5; $i++) {
            $response = $this->actingAs($this->user, 'api-guard')
                ->postJson('/api/v1/encryption/setup', [
                    'encryption_salt' => 'test_salt',
                    'encryption_test_value' => 'test_value',
                    'encryption_version' => 1
                ]);
        }
        
        // Should get rate limited
        $response->assertStatus(429);
    }
}
