<?php

namespace App\Http\Middleware;

use App\Facades\Settings;
use App\Models\User;
use Closure;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RejectIfSsoOnlyAndNotForAdmin
{
    /**
     * Reject the request when it aims to modify or impact a user account in those 2 conditions:
     * - The impacted account does not have the Administrator role
     * - Authentication is restricted to SSO only
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if (Settings::get('useSsoOnly')) {
            if ($email = $request->input('email', null)) {
                // A7: the email-lookup path used to answer 405 for a
                // non-admin email while letting an admin email proceed to
                // the login flow (401/200) — an admin-email enumeration
                // oracle. Non-admin and unknown emails now get the exact
                // same generic 401 'unauthorized' shape a failed login
                // produces, making the three outcomes indistinguishable
                // without the password.
                if (! User::whereEmail($email)->first()?->isAdministrator()) {
                    return response()->json(['message' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
                }
            } else {
                $user = Auth::user();

                if (! $user?->isAdministrator()) {
                    Log::notice(sprintf('Request to %s rejected, only Admins can request it while authentication is restricted to SSO only', $request->getPathInfo()));

                    return response()->json(['message' => __('error.unsupported_with_sso_only')], Response::HTTP_METHOD_NOT_ALLOWED);
                }
            }
        }

        return $next($request);
    }
}
