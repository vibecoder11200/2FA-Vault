<?php

namespace App\Http\Controllers\Auth;

use App\Api\v1\Resources\UserResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\UserSession;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\AuthenticatesUsers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application.
    | The controller uses a trait to conveniently provide its functionality
    | to your applications.
    |
    */

    use AuthenticatesUsers;

    /**
     * The login throttle.
     *
     * @var int
     */
    protected $maxAttempts;

    /**
     * Handle a login request to the application.
     *
     * @return \Illuminate\Http\JsonResponse
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function login(LoginRequest $request)
    {
        Log::info(sprintf('User login requested by %s from %s', var_export($request['email'], true), $request->ip()));

        $this->maxAttempts = config('auth.throttle.login');

        // If the class is using the ThrottlesLogins trait, we can automatically throttle
        // the login attempts for this application. We'll key this by the username and
        // the IP address of the client making these requests into this application.
        if ($this->hasTooManyLoginAttempts($request)) {
            $this->fireLockoutEvent($request);

            Log::notice(sprintf(
                '%s from %s locked-out, too many failed login attempts (using email+password)',
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
            'Failed login for %s from %s - Attemp %d/%d (using email+password)',
            var_export($request['email'], true),
            $request->ip(),
            $this->limiter()->attempts($this->throttleKey($request)),
            $this->maxAttempts()
        ));

        return $this->sendFailedLoginResponse($request);
    }

    /**
     * log out current user
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function logout(Request $request)
    {
        $userId = $request->user()->id;

        // Drop the session record for this device
        UserSession::where('user_id', $userId)
            ->where('token_id', $request->session()->getId())
            ->delete();

        $this->guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        Log::info(sprintf('User ID #%s logged out', $userId));

        return response()->json(['message' => 'signed out'], Response::HTTP_OK);
    }

    /**
     * Send the response after the user was authenticated.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendLoginResponse(Request $request)
    {
        $this->clearLoginAttempts($request);

        /**
         * @var \App\Models\User|null
         */
        $user = $this->guard()->user();

        $this->authenticated($request, $user);

        // Session fixation hardening: the pre-authentication session id must
        // not survive authentication. Must run BEFORE the UserSession row is
        // recorded so token_id holds the regenerated session id.
        $request->session()->regenerate();

        // Record the active session for the session-management feature.
        // token_id holds the Laravel session id (web-guard) or a Passport token id.
        UserSession::updateOrCreate(
            ['token_id' => $request->session()->getId()],
            [
                'user_id'        => $user->id,
                'ip_address'     => $request->ip(),
                'user_agent'     => $request->userAgent(),
                'last_active_at' => now(),
            ]
        );

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
    protected function sendFailedLoginResponse(Request $request)
    {
        return response()->json(['message' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Redirect the user after determining they are locked out.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    protected function sendLockoutResponse(Request $request)
    {
        $seconds = $this->limiter()->availableIn(
            $this->throttleKey($request)
        );

        return response()->json(['message' => Lang::get('message.throttle', ['seconds' => $seconds])], Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array
     */
    protected function credentials(Request $request)
    {
        $credentials = [
            $this->username() => strtolower($request->input($this->username())),
            'password'        => $request->get('password'),
            // Deactivated users cannot log back in (A1)
            'is_active' => true,
        ];

        return $credentials;
    }

    /**
     * The user has been authenticated.
     *
     * @param  mixed  $user
     * @return void
     */
    protected function authenticated(Request $request, $user)
    {
        $user->last_seen_at = Carbon::now()->format('Y-m-d H:i:s');

        if ($user->encryption_enabled && $user->encryption_version > 0) {
            $user->vault_locked = true;
        }

        $user->save();

        Log::info(sprintf('User ID #%s authenticated (using email+password)', $user->id));
    }
}
