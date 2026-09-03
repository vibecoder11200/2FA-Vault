<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\Request;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    /**
     * Validate the email for the given request.
     *
     * @return void
     */
    protected function validateEmail(Request $request)
    {
        // A8: the exists-rule deliberately stays (upstream 2FAuth UX parity).
        // The route is throttled (3/min/IP), which bounds the enumeration
        // oracle this creates.
        $request->validate(['email' => 'required|exists:users,email']);
    }
}
