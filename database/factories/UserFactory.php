<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{

    const USER_PASSWORD = 'password';

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => Hash::make(self::USER_PASSWORD),
            'remember_token' => Str::random(10),
            'is_admin' => false,
        ];
    }

    /**
     * Indicate that the user is an administrator.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
     */
    public function administrator()
    {
        return $this->state(function (array $attributes) {
            return [
                'is_admin' => true,
            ];
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
     */
    public function unverified()
    {
        return $this->state(function (array $attributes) {
            return [
                'email_verified_at' => null,
            ];
        });
    }

    /**
     * Indicate that the user has E2EE enabled.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
     */
    public function withE2EE()
    {
        return $this->state(function (array $attributes) {
            return [
                'encryption_enabled' => true,
                'encryption_salt' => base64_encode(random_bytes(32)),
                'encryption_test_value' => json_encode([
                    'ciphertext' => base64_encode(random_bytes(32)),
                    'iv' => base64_encode(random_bytes(12)),
                    'authTag' => base64_encode(random_bytes(16)),
                ]),
                'encryption_version' => 1,
                'vault_locked' => false,
            ];
        });
    }

    /**
     * Indicate that the user's vault is locked.
     *
     * @return \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
     */
    public function withLockedVault()
    {
        return $this->withE2EE()->state(function (array $attributes) {
            return [
                'vault_locked' => true,
            ];
        });
    }

    public function e2eAdmin()
    {
        return $this->administrator()->state(function (array $attributes) {
            return [
                'name' => 'E2E Admin',
                'email' => 'e2eadmin@2fauth.app',
            ];
        });
    }

    public function e2eUser()
    {
        return $this->state(function (array $attributes) {
            return [
                'name' => 'E2E User',
                'email' => 'e2euser@2fauth.app',
            ];
        });
    }

    public function e2eEncrypted()
    {
        return $this->withE2EE()->state(function (array $attributes) {
            return [
                'name' => 'E2E Encrypted',
                'email' => 'e2eencrypted@2fauth.app',
            ];
        });
    }

    public function e2eLockedEncrypted()
    {
        return $this->withLockedVault()->state(function (array $attributes) {
            return [
                'name' => 'E2E Locked',
                'email' => 'e2elocked@2fauth.app',
            ];
        });
    }

    public function e2eConflictUser()
    {
        return $this->withE2EE()->state(function (array $attributes) {
            return [
                'name' => 'E2E Conflict',
                'email' => 'e2econflict@2fauth.app',
            ];
        });
    }

    public function e2eBackupUser()
    {
        return $this->withE2EE()->state(function (array $attributes) {
            return [
                'name' => 'E2E Backup',
                'email' => 'e2ebackup@2fauth.app',
            ];
        });
    }
}