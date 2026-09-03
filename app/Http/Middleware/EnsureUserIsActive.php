<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Handle an incoming request.
     *
     * Rejects any request whose authenticated user has been deactivated by an
     * administrator:
     * - Bearer-token (PAT) requests get a 401 so extensions/CLI treat the
     *   token as invalid.
     * - Session-cookie requests are logged out (session invalidated) and get
     *   a 401.
     *
     * Implementation note: the user is obtained through `$request->user()`
     * only. Resolving a guard directly (e.g. `$request->user('api-guard')`)
     * must be avoided: Passport's TokenGuard BLANKS the Authorization header
     * as a side effect when the bearer token fails JWT validation, which
     * would destroy legitimate non-Passport bearer tokens (e.g. the metrics
     * endpoint token) for every later middleware in the chain. The
     * Authenticate middleware runs before this one (see $middlewarePriority
     * in Kernel.php) and installs the correct user resolver per guard.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next) : Response
    {
        // Strict false comparison: the attribute is 0/1 once loaded from the
        // database, but may be null on in-memory user instances that were
        // never persisted with the column.
        $user = $request->user();

        if ($user && $user->is_active === false) {
            if (! $request->bearerToken()) {
                Auth::guard('web-guard')->logout();

                if ($request->hasSession()) {
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
            }

            return response()->json([
                'message' => 'Your account has been deactivated. Please contact an administrator.',
            ], 401);
        }

        return $next($request);
    }
}
