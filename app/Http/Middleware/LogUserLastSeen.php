<?php

namespace App\Http\Middleware;

use App\Models\UserSession;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Auth;

class LogUserLastSeen
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $guards
     * @return mixed
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            // We do not track activity of guests. B16: bearer-token requests
            // (extension / CLI) now count as activity too — skipping them made
            // API-only owners falsely trigger the emergency dead man's switch.
            if (Auth::guard($guard)->check()) {
                Auth::guard($guard)->user()->last_seen_at = Carbon::now()->format('Y-m-d H:i:s');
                Auth::guard($guard)->user()->save();

                // Keep the user_sessions.last_active_at bookkeeping fresh so
                // the session list is not permanently stale (A3). Only for
                // web-guard cookie requests whose session id is recorded in
                // user_sessions.token_id.
                if ($request->hasSession()) {
                    UserSession::where('user_id', Auth::guard($guard)->user()->id)
                        ->where('token_id', $request->session()->getId())
                        ->update(['last_active_at' => Carbon::now()]);
                }

                break;
            }
        }

        return $next($request);
    }
}
