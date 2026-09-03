<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The 'cancelled' status was already written by cancelInvitation() and
        // by B3 member-removal revocation, but the enum CHECK constraint did
        // not include it (SQLite enforced it, MySQL in non-strict mode would
        // have stored ''). Widen the enum.
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->enum('status', ['pending', 'accepted', 'rejected', 'expired', 'cancelled'])->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->enum('status', ['pending', 'accepted', 'rejected', 'expired'])->default('pending')->change();
        });
    }
};
