<?php

namespace App\Http\Controllers\Auth;

use App\Facades\Settings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SocialiteController extends Controller
{
    /**
     * Redirect to the provider's authentication url
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Illuminate\Http\RedirectResponse
     */
    public function redirect(Request $request, string $driver)
    {
        // Generic SSO provider definition check
        if (! config('services.' . $driver . '.client_id') || ! config('services.' . $driver . '.client_secret')) {
            return redirect('/error?err=sso_bad_provider_setup');
        }

        // OpenID SSO provider definition check
        if ($driver == 'openid' && (! config('services.openid.token_url') || ! config('services.openid.authorize_url') || ! config('services.openid.userinfo_url'))) {
            return redirect('/error?err=sso_bad_provider_setup');
        }

        return Settings::get('enableSso')
            ? Socialite::driver($driver)->redirect()
            : redirect('/error?err=sso_disabled');
    }

    /**
     * Register (if needed) the user and authenticate him
     *
     * @return \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse
     */
    public function callback(Request $request, string $driver)
    {
        try {
            $socialiteUser = Socialite::driver($driver)->user();
        } catch (\Exception $e) {
            Log::error($e);

            return redirect('/error?err=sso_failed');
        }

        $uniqueName     = $socialiteUser->getId() . '@' . $driver;
        $socialiteEmail = strtolower($socialiteUser->getEmail() ?? $uniqueName);
        $socialiteName  = ($socialiteUser->getNickname() ?? $socialiteUser->getName()) . ' (' . $uniqueName . ')';
        $oauthId        = strtolower($socialiteUser->getId());

        // SQLite requires case insensitive collation for comparisons
        $user = (DB::connection()->getDriverName() === 'sqlite')
            ? User::whereRaw('oauth_id = ? COLLATE NOCASE', [$oauthId])
                ->whereRaw('oauth_provider = ? COLLATE NOCASE', [$driver])
                ->first() ?? new User([
                    'oauth_id'       => $socialiteUser->getId(),
                    'oauth_provider' => $driver,
                ])
            : User::firstOrNew([
                'oauth_id'       => $socialiteUser->getId(),
                'oauth_provider' => $driver,
            ]);

        if (! $user->exists) {
            if (DB::table('users')
                ->whereRaw('email = ?' . (DB::connection()->getDriverName() === 'sqlite' ? ' COLLATE NOCASE' : ''), [$socialiteEmail])
                ->exists()) {
                return redirect('/error?err=sso_email_already_used');
            } elseif (User::count() === 0) {
                $user->promoteToAdministrator();
            } elseif (Settings::get('disableRegistration') && ! Settings::get('keepSsoRegistrationEnabled')) {
                return redirect('/error?err=sso_no_register');
            }
            $user->password = Hash::make(Str::random());
        }

        $user->email        = $socialiteEmail;
        $user->name         = $socialiteName;
        $user->last_seen_at = Carbon::now()->format('Y-m-d H:i:s');
        $user->save();

        Auth::guard()->login($user);

        // Session fixation hardening: the pre-authentication session id must
        // not survive authentication.
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return redirect('/accounts');
    }
}
