<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * Indicates whether the XSRF-TOKEN cookie should be set on the response.
     *
     * @var bool
     */
    protected $addHttpCookie = true;

    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];

    /**
     * Determine if the request URI is in the except array.
     *
     * In the `e2e` environment we deliberately exempt the public
     * authentication endpoints from CSRF verification. The php built-in
     * dev server used by Playwright does not reliably round-trip the
     * session cookie on the very first POST, which causes flakes that
     * do not reflect real-world behavior. Production (and `testing`)
     * are unaffected.
     */
    protected function inExceptArray($request)
    {
        if (app()->environment('e2e') && $request->is(
            'user/login',
            'user',
            'user/password/lost',
            'user/password/reset',
            'webauthn/login',
            'webauthn/login/options',
            'webauthn/lost',
            'webauthn/recover',
        )) {
            return true;
        }

        return parent::inExceptArray($request);
    }
}
