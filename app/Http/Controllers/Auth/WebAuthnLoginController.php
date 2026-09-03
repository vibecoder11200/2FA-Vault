<?php

namespace App\Http\Controllers\Auth;

use App\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\WebauthnAssertedRequest;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Enums\UserVerification;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

class WebAuthnLoginController extends Controller
{
    use AuthenticatesUsers;

    /**
     * The login throttle.
     *
     * @var int
     */
    protected $maxAttempts;

    /*
    |--------------------------------------------------------------------------
    | WebAuthn Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller allows the WebAuthn user device to request a login and
    | return the correctly signed challenge. Most of the hard work is done
    | by your Authentication Guard once the user is attempting to login.
    |
    */

    /**
     * Returns the challenge to assertion.
     */
    public function options(AssertionRequest $request) : Responsable|JsonResponse
    {
        switch (config('webauthn.user_verification')) {
            case UserVerification::Discouraged:
                $request = $request->fastLogin();    // Makes the authenticator to only check for user presence on registration
                break;
            case UserVerification::Required:
                $request = $request->secureLogin();  // Makes the authenticator to always verify the user thoroughly on registration
                break;
        }

        $validated = $request->validate([
            'email' => [
                'required',
                'email',
            ],
        ]);

        // Return a 200-shaped generic response for an unknown email instead
        // of a 422 validation failure: the 200-vs-422 difference was a
        // registered-email enumeration oracle (A8). The response body is
        // deliberately identical to what the SPA already handles for failed
        // challenges. Note: the password login and forgot-password flows
        // intentionally keep their exists-rules (upstream UX parity); they
        // are throttled, which bounds the oracle.
        if (! User::whereRaw('email = ?' . (DB::connection()->getDriverName() === 'sqlite' ? ' COLLATE NOCASE' : ''), [strtolower($validated['email'])])->exists()) {
            return response()->json(['message' => __('auth.failed')], 200);
        }

        return $request->toVerify($validated);
    }

    /**
     * Log the user in.
     *
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    public function login(WebauthnAssertedRequest $request)
    {
        Log::info(sprintf('User login via webauthn requested by %s from %s', var_export($request['email'], true), $request->ip()));

        $this->maxAttempts = config('auth.throttle.login');

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            Log::notice(sprintf(
                '%s from %s locked-out, too many failed login attempts (using webauthn)',
                var_export($request['email'], true),
                $request->ip()
            ));

            return $this->sendLockoutResponse($request);
        }

        if ($this->attemptLogin($request)) {
            return $this->sendLoginResponse($request);
        }

        // If the login attempt was unsuccessful we will increment the number of attempts
        // to login and redirect the user back to the login form. Of course, when this
        // user surpasses their maximum number of attempts they will get locked out.
        $this->incrementLoginAttempts($request);

        Log::notice(sprintf(
            'Failed login for %s from %s - Attemp %d/%d (using webauthn)',
            var_export($request['email'], true),
            $request->ip(),
            $this->limiter()->attempts($this->throttleKey($request)),
            $this->maxAttempts()
        ));

        return response()->json(['message' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Attempt to log the user into the application.
     *
     * @return bool
     */
    protected function attemptLogin(WebauthnAssertedRequest $request)
    {
        return ! is_null($request->login());
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendLoginResponse(WebauthnAssertedRequest $request)
    {
        $this->clearLoginAttempts($request);

        // Session fixation hardening: the pre-authentication session id must
        // not survive authentication.
        $request->session()->regenerate();

        /**
         * @var \App\Models\User|null
         */
        $user = $this->guard()->user();

        $this->authenticated($user);

        return response()->json(
            array_merge([
                'message' => 'authenticated',
            ], (new UserResource($user))->resolve($request)),
            Response::HTTP_OK
        );
    }

    /**
     * Get the failed login response instance.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendFailedLoginResponse(WebauthnAssertedRequest $request)
    {
        return response()->json(['message' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Redirect the user after determining they are locked out.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendLockoutResponse(WebauthnAssertedRequest $request)
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        return response()->json(['message' => Lang::get('message.throttle', ['seconds' => $seconds])], Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * Get the login username to be used by the controller.
     *
     * @return string
     */
    public function username()
    {
        return 'email';
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array
     */
    protected function credentials(WebauthnAssertedRequest $request)
    {
        $credentials = [
            $this->username() => strtolower($request->input($this->username())),
        ];

        return $credentials;
    }

    /**
     * The user has been authenticated.
     *
     * @param  mixed  $user
     * @return void|\Illuminate\Http\JsonResponse
     */
    protected function authenticated($user)
    {
        $user->last_seen_at = Carbon::now()->format('Y-m-d H:i:s');

        if ($user->encryption_enabled && $user->encryption_version > 0) {
            $user->vault_locked = true;
        }

        $user->save();

        Log::info(sprintf('User ID #%s authenticated (using webauthn)', $user->id));
    }
}
