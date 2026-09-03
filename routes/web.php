<?php

use App\Http\Controllers\Admin\MetricsController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PersonalAccessTokenController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Auth\UserController;
use App\Http\Controllers\Auth\WebAuthnDeviceLostController;
use App\Http\Controllers\Auth\WebAuthnLoginController;
use App\Http\Controllers\Auth\WebAuthnManageController;
use App\Http\Controllers\Auth\WebAuthnRecoveryController;
use App\Http\Controllers\Auth\WebAuthnRegisterController;
use App\Http\Controllers\SinglePageController;
use App\Http\Controllers\SystemController;
use App\Http\Middleware\CustomCreateFreshApiToken;
use App\Http\Middleware\MetricsAuthMiddleware;
use App\Http\Middleware\SetLanguage;
use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
// use Illuminate\Foundation\Events\DiagnosingHealth;
// use Illuminate\Support\Facades\Event;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;

// use App\Models\User;
// use App\Notifications\SignedInWithNewDeviceNotification;
// use App\Models\AuthLog;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

/**
 * Routes that only work for unauthenticated user (otherwise, the user is logged out)
 */
Route::group(['middleware' => ['rejectIfDemoMode', 'RejectIfSsoOnlyAndNotForAdmin', 'forceLogout', 'setLanguage']], function () {
    Route::post('user', [RegisterController::class, 'register'])->name('user.register')->middleware('throttle:5,60');
    Route::post('user/password/lost', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('user.password.lost')->middleware('throttle:3,60');
    Route::post('user/password/reset', [ResetPasswordController::class, 'reset'])->name('password.reset')->middleware('throttle:3,60');
    // WebAuthn login challenge endpoint — rate-limit to match the login routes
    // (10/min/IP) so the unauthenticated assertion-options endpoint cannot be
    // abused for challenge flooding or email enumeration.
    Route::post('webauthn/login/options', [WebAuthnLoginController::class, 'options'])->name('webauthn.login.options')->middleware('throttle:10,1');
    // Device-lost recovery email — same class of risk as password-lost above
    // (outbound email, user enumeration), so it gets the same 3/min/IP cap.
    Route::post('webauthn/lost', [WebAuthnDeviceLostController::class, 'sendRecoveryEmail'])->name('webauthn.lost')->middleware('throttle:3,60');
});

/**
 * Routes that can be requested max 10 times per minute by the same IP
 */
Route::group(['middleware' => ['rejectIfDemoMode', 'throttle:10,1', 'RejectIfSsoOnlyAndNotForAdmin', 'forceLogout']], function () {
    Route::post('webauthn/recover', [WebAuthnRecoveryController::class, 'recover'])->name('webauthn.recover');
});

/**
 * Routes that only work for unauthenticated user (otherwise, the user is logged out)
 * that can be requested max 10 times per minute by the same IP
 */
Route::group(['middleware' => ['forceLogout', 'throttle:10,1']], function () {
    Route::post('user/login', [LoginController::class, 'login'])->name('user.login')->middleware('RejectIfSsoOnlyAndNotForAdmin');
    Route::post('webauthn/login', [WebAuthnLoginController::class, 'login'])->name('webauthn.login')->middleware('RejectIfSsoOnlyAndNotForAdmin');

    Route::get('/socialite/redirect/{driver}', [SocialiteController::class, 'redirect'])->name('socialite.redirect');
    Route::get('/socialite/callback/{driver}', [SocialiteController::class, 'callback'])->name('socialite.callback');
});

/**
 * Routes protected by an authentication guard but rejected when the reverse-proxy
 * guard is enabled
 */
Route::group(['middleware' => ['behind-auth', 'rejectIfReverseProxy']], function () {
    Route::put('user', [UserController::class, 'update'])->name('user.update')->middleware('throttle:user-mutations');
    Route::patch('user/password', [PasswordController::class, 'update'])->name('user.password.update')->middleware('rejectIfDemoMode', 'throttle:5,1');
    // A10: logout is POST + CSRF-protected. The SPA caller is switched in a
    // later phase; the legacy GET route below answers 410 in the meantime.
    Route::post('user/logout', [LoginController::class, 'logout'])->name('user.logout');
    Route::get('user/logout', function () {
        return response()->json(['message' => 'Logout must be requested using POST.'], 410);
    })->name('user.logout.deprecated');
    Route::delete('user', [UserController::class, 'delete'])->name('user.delete')->middleware('rejectIfDemoMode');

    // Following routes are also forbidden to regular users when "SSO only" is enabled, but using Authorization gates
    Route::get('oauth/personal-access-tokens', [PersonalAccessTokenController::class, 'forUser'])->name('passport.personal.tokens.index')->middleware('throttle:10,1');
    Route::post('oauth/personal-access-tokens', [PersonalAccessTokenController::class, 'store'])->name('passport.personal.tokens.store')->middleware('throttle:10,1');
    Route::delete('oauth/personal-access-tokens/{token_id}', [PersonalAccessTokenController::class, 'destroy'])->name('passport.personal.tokens.destroy')->middleware('throttle:10,1');

    Route::post('webauthn/register/options', [WebAuthnRegisterController::class, 'options'])->name('webauthn.register.options')->middleware('throttle:10,1');
    Route::post('webauthn/register', [WebAuthnRegisterController::class, 'register'])->name('webauthn.register')->middleware('throttle:10,1');
    Route::get('webauthn/credentials', [WebAuthnManageController::class, 'index'])->name('webauthn.credentials.index');
    Route::patch('webauthn/credentials/{credential}/name', [WebAuthnManageController::class, 'rename'])->name('webauthn.credentials.rename');
    Route::delete('webauthn/credentials/{credential}', [WebAuthnManageController::class, 'delete'])->name('webauthn.credentials.delete');
});

/**
 * Routes protected by an authentication guard and restricted to administrators
 */
Route::group(['middleware' => ['behind-auth', 'admin']], function () {
    Route::get('system/infos', [SystemController::class, 'infos'])->name('system.infos');
    Route::post('system/test-email', [SystemController::class, 'testEmail'])->name('system.testEmail');
    Route::get('system/latestRelease', [SystemController::class, 'latestRelease'])->name('system.latestRelease');
    Route::get('system/optimize', [SystemController::class, 'optimize'])->name('system.optimize');
    Route::get('system/clear-cache', [SystemController::class, 'clear'])->name('system.clear');
});

Route::get('refresh-csrf', function (Request $request) {
    $request->session()->regenerateToken();

    return response()->json([
        'token' => csrf_token(),
    ], 200, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'Pragma'        => 'no-cache',
    ]);
})->middleware('throttle:30,1');

/**
 * Prometheus metrics endpoint
 * Protected by IP allowlist or Bearer token authentication
 */
Route::get('/metrics', [MetricsController::class, 'index'])
    ->name('metrics')
    ->middleware(MetricsAuthMiddleware::class, 'throttle:10,1');

Route::withoutMiddleware([
    StartSession::class,
    VerifyCsrfToken::class,
    SubstituteBindings::class,
    SetLanguage::class,
    CustomCreateFreshApiToken::class,
])->get('/up', function () {
    // Event::dispatch(new DiagnosingHealth);
    return view('health', [
        'isSecure' => str_starts_with(config('app.url'), 'https'),
    ]);
});

/**
 * Sentry integration test route.
 * Only registered when explicitly enabled via SENTRY_TEST_ENABLED=true, so it
 * is never exposed in production by default. Hit it once after configuring
 * SENTRY_DSN to confirm events arrive in your Sentry dashboard, then unset
 * SENTRY_TEST_ENABLED again. The thrown exception propagates to the client as
 * a 500 and is captured by the reportable hook in app/Exceptions/Handler.php.
 */
if (filter_var(env('SENTRY_TEST_ENABLED', false), FILTER_VALIDATE_BOOLEAN)) {
    Route::get('/sentry-test', function () {
        throw new \RuntimeException('Sentry integration test from 2FA-Vault');
    });
}

// Route::get('/notification', function () {
//     $user = User::find(1);
//     return (new SignedInWithNewDeviceNotification(AuthLog::find(9)))
//         ->toMail($user);
// });

/**
 * Route for the main landing view
 */
Route::get('/{any}', [SinglePageController::class, 'index'])->where('any', '.*')->name('landing');
