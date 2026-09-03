<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Revokes every usable credential of a user: Passport tokens (PATs),
 * user_sessions bookkeeping rows, the real Laravel session rows when the
 * database session driver is in use, and re-arms the E2EE vault lock.
 *
 * Used whenever a password is changed or reset so that stolen sessions or
 * PATs cannot outlive the credential they were obtained with.
 */
class CredentialRevocationService
{
    /**
     * Revoke all tokens, sessions and re-arm the vault lock for the user.
     */
    public function revokeAllFor(User $user) : void
    {
        // 1. Revoke ALL Passport access tokens of the user — deliberately a
        //    direct update instead of TokenRepository::forUser(), which only
        //    returns non-revoked, unexpired tokens whose client provider
        //    matches; revocation must not depend on those filters.
        $now = now();
        DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)
            ->update(['revoked' => true, 'updated_at' => $now]);

        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', function ($query) use ($user) {
                $query->select('id')->from('oauth_access_tokens')
                    ->where('user_id', $user->id);
            })
            // The refresh-token table has no updated_at column in the
            // Passport schema, so only the revoked flag is flipped.
            ->update(['revoked' => true]);

        // 2. Evict real Laravel session rows (database driver only).
        // Web-guard user_sessions rows store the Laravel session id in
        // token_id (see LoginController::sendLoginResponse), while PAT rows
        // store the Passport token id, which never collides with a session id.
        if (config('session.driver') === 'database') {
            $sessionIds = $user->sessions()->pluck('token_id')->all();
            DB::table('sessions')->whereIn('id', $sessionIds)->delete();
        }

        // 3. Drop the user_sessions bookkeeping rows.
        UserSession::where('user_id', $user->id)->delete();

        // 4. Re-arm the vault lock so a stolen session cannot resume with an
        //    unlocked vault. vault_locked is client-attested under E2EE, but
        //    re-arming forces a fresh master-password challenge on next use.
        if ($user->encryption_enabled && $user->encryption_version > 0) {
            $user->vault_locked = true;
            $user->save();
        }

        Log::notice(sprintf('All credentials of User ID #%s have been revoked (password change/reset)', $user->id));
    }
}
