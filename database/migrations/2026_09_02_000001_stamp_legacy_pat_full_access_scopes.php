<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time cutover migration for PAT scopes (A6 / RT1).
 *
 * Every oauth_access_tokens row created before scopes were enforced carries
 * an empty scope set. These pre-cutover tokens keep working by stamping them
 * with the explicit `legacy_full_access` marker — the ONLY thing the scope
 * middleware treats as full access. Plain emptiness is never grandfathered
 * because Passport's PersonalAccessTokenController::store() defaults omitted
 * scopes to [], which would let anyone mint a new "legacy" full-access token.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up() : void
    {
        DB::table('oauth_access_tokens')
            ->where(function ($query) {
                $query->whereNull('scopes')->orWhereIn('scopes', ['', '[]']);
            })
            ->update(['scopes' => json_encode(['legacy_full_access'])]);
    }

    /**
     * Reverse the migrations.
     *
     * The stamp cannot be reliably reverted (some stamped tokens may have
     * been created after the cutover by the overridden controller), so the
     * marker is simply dropped back to an empty scope set.
     */
    public function down() : void
    {
        DB::table('oauth_access_tokens')
            ->where('scopes', json_encode(['legacy_full_access']))
            ->update(['scopes' => '[]']);
    }
};
