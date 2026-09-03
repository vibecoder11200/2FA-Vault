<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\CredentialRevocationService;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset requests
    | and uses a simple trait to include this behavior in your application's
    | users. The user's password.
    |
    */

    use ResetsPasswords;

    /**
     * Reset the given user's password.
     *
     * Overrides ResetsPasswords::resetPassword() (body replicated because a
     * trait method cannot be invoked through parent::) so a successful
     * self-service password reset also revokes all Passport tokens, evicts
     * the user's sessions and re-arms the E2EE vault lock (see
     * CredentialRevocationService).
     *
     * @param  \App\Models\User  $user
     * @param  string  $password
     * @return void
     */
    protected function resetPassword($user, $password)
    {
        $this->setUserPassword($user, $password);

        $user->setRememberToken(Str::random(60));

        $user->save();

        event(new \Illuminate\Auth\Events\PasswordReset($user));

        $this->guard()->login($user);

        app(CredentialRevocationService::class)->revokeAllFor($user->refresh());
    }

    /**
     * Set the user's password.
     *
     * @param  \App\Models\User  $user
     * @param  string  $password
     * @return void
     */
    protected function setUserPassword($user, $password)
    {
        $user->password = Hash::make($password);
    }
}
