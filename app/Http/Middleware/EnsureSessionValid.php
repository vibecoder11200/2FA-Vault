<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects session-cookie requests whose underlying session row no longer
 * exists (revoked/evicted server-side).
 *
 * Scope (RT2 constraint):
 * - Bearer/PAT requests (extension, CLI) pass through untouched — they never
 *   rely on the Laravel session store.
 * - Only applies when the session data actually lives in the database. On a
 *   file/redis driver deployment there is no queryable session row, so the
 *   middleware no-ops gracefully (no DB errors).
 * - On the database driver with a missing `sessions` table it also no-ops
 *   with a warning log instead of failing the request.
 */
class EnsureSessionValid
{
    /**
     * Per-process cache of the sessions-table existence probe so the schema
     * check does not run on every request.
     */
    private static ?bool $sessionsTableExists = null;

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next) : Response
    {
        // Bearer-token (PAT) traffic never touches the session store.
        if ($request->bearerToken()) {
            return $next($request);
        }

        if (config('session.driver') !== 'database' || ! $request->hasSession()) {
            return $next($request);
        }

        // A session row is only persisted when the response is sent, so a
        // first-visit request that does not carry a session cookie yet must
        // never be rejected (it has no row by construction). The cookie value
        // (not the store's current id) is authoritative here: it is the id
        // the client actually presents.
        $cookieName = config('session.cookie');
        $cookieId   = $request->cookie($cookieName);

        if (! is_string($cookieId) || $cookieId === '') {
            return $next($request);
        }

        if (self::$sessionsTableExists === null) {
            self::$sessionsTableExists = Schema::hasTable('sessions');
        }

        if (! self::$sessionsTableExists) {
            Log::warning('EnsureSessionValid: sessions table not found although SESSION_DRIVER=database, skipping validation');

            return $next($request);
        }

        $exists = Schema::getConnection()->table('sessions')
            ->where('id', $cookieId)
            ->exists();

        if (! $exists) {
            // The cookie points at a row that is gone (expired, GC'd or
            // revoked). Requests that are not authenticated through a
            // session-cookie guard fall through to the normal guest flow
            // (protected routes still 401 via the Authenticate middleware).
            // Note the api-guard check: the SPA also authenticates through
            // Passport's encrypted laravel_token cookie, which rides on the
            // same session cookie — evicting the session row must evict it.
            $webUser = $request->user('web-guard');
            $apiUser = $request->user('api-guard');

            if ($webUser === null && $apiUser === null) {
                return $next($request);
            }

            Log::notice('Session rejected: the session row has been revoked server-side');

            if ($webUser !== null) {
                Auth::guard('web-guard')->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'session revoked'], 401);
        }

        return $next($request);
    }
}
