<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_contacts', function (Blueprint $table) {
            // SHA-256 fingerprint (hex) of the grantee RSA public key (SPKI, base64)
            // as registered at wrap time. Used by the vault-data endpoint to detect
            // key rotation and fail closed (RT3).
            $table->string('grantee_public_key_fingerprint', 64)->nullable()->after('encrypted_key');
        });
    }

    public function down(): void
    {
        Schema::table('emergency_contacts', function (Blueprint $table) {
            $table->dropColumn('grantee_public_key_fingerprint');
        });
    }
};
