<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\TwoFAccount;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class E2eSeeder extends Seeder
{
    public function run(): void
    {
        // Admin user with pre-existing accounts (for CRUD/group testing)
        $admin = User::create([
            'name' => 'E2E Admin',
            'email' => 'e2eAdmin@2fauth.app',
            'password' => Hash::make('password'),
            'is_admin' => 1,
        ]);

        // Regular user (empty, sees /start page on first login)
        $user = User::create([
            'name' => 'E2E User',
            'email' => 'e2eUser@2fauth.app',
            'password' => Hash::make('password'),
            'is_admin' => 0,
        ]);

        // User with E2EE enabled (for encryption flow testing)
        $encryptedUser = User::create([
            'name' => 'E2E Encrypted',
            'email' => 'e2eEncrypted@2fauth.app',
            'password' => Hash::make('password'),
            'is_admin' => 0,
            'encryption_salt' => base64_encode(random_bytes(32)),
            'encryption_test_value' => json_encode([
                'ciphertext' => base64_encode(random_bytes(32)),
                'iv' => base64_encode(random_bytes(12)),
                'authTag' => base64_encode(random_bytes(16)),
            ]),
            'encryption_version' => 1,
            'vault_locked' => false,
        ]);

        // Pre-populate accounts for admin (for account CRUD tests)
        $group = Group::create([
            'name' => 'E2E Test Group',
            'user_id' => $admin->id,
        ]);

        TwoFAccount::create([
            'user_id' => $admin->id,
            'group_id' => $group->id,
            'otp_type' => 'totp',
            'account' => 'admin@test.com',
            'service' => 'GitHub',
            'secret' => 'A4GRFTVVRBGY7UIW',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
            'legacy_uri' => 'otpauth://totp/GitHub:admin@test.com?secret=A4GRFTVVRBGY7UIW&issuer=GitHub',
        ]);

        TwoFAccount::create([
            'user_id' => $admin->id,
            'otp_type' => 'totp',
            'account' => 'user@example.com',
            'service' => 'Google',
            'secret' => 'JBSWY3DPEHPK3PXP',
            'algorithm' => 'sha1',
            'digits' => 6,
            'period' => 30,
            'legacy_uri' => 'otpauth://totp/Google:user@example.com?secret=JBSWY3DPEHPK3PXP&issuer=Google',
        ]);
    }
}
