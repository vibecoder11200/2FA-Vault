<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Salt for Argon2id key derivation (base64 encoded, 32 bytes)
            $table->string('encryption_salt', 255)->nullable()->after('password');
            
            // Encrypted test value for zero-knowledge password verification
            $table->text('encryption_test_value')->nullable()->after('encryption_salt');
            
            // Encryption version for future compatibility
            $table->tinyInteger('encryption_version')->default(0)->after('encryption_test_value');
            
            // Vault lock status (session-based)
            $table->boolean('vault_locked')->default(false)->after('encryption_version');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'encryption_salt',
                'encryption_test_value',
                'encryption_version',
                'vault_locked'
            ]);
        });
    }
};
