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
        Schema::table('twofaccounts', function (Blueprint $table) {
            // Add flag to indicate if secret is encrypted
            $table->boolean('encrypted')->default(false)->after('secret');
        });
        
        // Note: The 'secret' field already exists and is of type TEXT
        // which is sufficient to store JSON-encoded encrypted data
        // {ciphertext: "...", iv: "...", authTag: "..."}
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('twofaccounts', function (Blueprint $table) {
            $table->dropColumn('encrypted');
        });
    }
};
