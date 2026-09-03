<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Exceptions\MissingScopeException;
use Laravel\Passport\TransientToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route scope middleware for Passport personal access tokens (A6 / RT1).
 *
 * Usage: ->middleware('pat.scopes:read,otp')
 *
 * Rules:
 * - Only the explicit `legacy_full_access` marker (stamped by the one-time
 *   cutover migration) grants full access. A plain empty scope set is NOT
 *   trusted — Passport defaults omitted scopes to [] on token creation.
 * - SPA requests authenticated via the Passport cookie (TransientToken) pass
 *   through: they are first-party session-cookie requests, not PATs.
 * - Otherwise the token must carry at least one of the required scopes.
 */
class EnsureTokenScope
{
    /**
     * The marker that grandfathered pre-cutover tokens carry.
     */
    public const LEGACY_FULL_ACCESS = 'legacy_full_access';

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$scopes) : Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user('api-guard') ?? $request->user();

        if ($user === null) {
            return $next($request);
        }

        $token = $user->token();

        if ($token === null || $token instanceof TransientToken) {
            // Session-cookie (first-party SPA) request: full access.
            return $next($request);
        }

        // Bridge AccessToken instances expose oauth_scopes, Eloquent Token
        // instances expose scopes (json-cast).
        /** @var array<int, string> $tokenScopes */
        $tokenScopes = $token->oauth_scopes ?? $token->scopes ?? [];

        if (in_array(self::LEGACY_FULL_ACCESS, $tokenScopes, true)) {
            return $next($request);
        }

        foreach ($scopes as $scope) {
            if (in_array($scope, $tokenScopes, true)) {
                return $next($request);
            }
        }

        throw new MissingScopeException($scopes);
    }
}
