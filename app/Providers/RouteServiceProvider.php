<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * This is used by Laravel authentication to redirect users after login.
     *
     * @var string
     */
    public const HOME = '/accounts';

    /**
     * The controller namespace for the application.
     *
     * When present, controller route declarations will automatically be prefixed with this namespace.
     *
     * @var string|null
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * Define your route model bindings, pattern filters, etc.
     */
    public function boot() : void
    {
        Route::pattern('settingName', '[a-zA-Z]+');

        // A12: THROTTLE_API=0 deliberately disables all /api/v1 throttling
        // (operator freedom), but it must not do so silently.
        if (intval(config('2fauth.api.throttle')) === 0) {
            Log::warning('THROTTLE_API=0: all /api/v1 rate limiting is disabled, including OTP and invitation endpoints');
        }

        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api/v1')
                ->middleware('api.v1')
                ->namespace($this->getApiNamespace('1'))
                ->group(base_path('routes/api/v1.php'));

            // Route::prefix('api/v2')
            //     ->middleware('api.v2')
            //     ->namespace($this->getApiNamespace(2))
            //     ->group(base_path('routes/api/v2.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Build Api namespace based on provided version
     *
     * @return string The Api namespace
     */
    private function getApiNamespace(string $version)
    {
        return 'App\Api\v' . $version . '\Controllers';
    }

    /**
     * Configure the rate limiters for the application.
     *
     * @return void
     */
    protected function configureRateLimiting()
    {
        RateLimiter::for('api', function (Request $request) {
            $maxAttempts = intval(config('2fauth.api.throttle'));

            if ($maxAttempts == 0) {
                return Limit::none();
            }

            if ($request->RouteIs('twofaccounts.store') && str_ends_with($request->header('referer'), 'account/import')) {
                $importMaxAttempts = intval(config('2fauth.api.throttleImport'));
                $maxAttempts       = $importMaxAttempts ? $importMaxAttempts + $maxAttempts : 0;
            }

            // A12: key by the authenticated user id when available so users
            // behind a shared NAT do not drain each other's budget; fall
            // back to the IP for unauthenticated requests.
            return $maxAttempts > 0 ? Limit::perMinute($maxAttempts)->by($request->user('api-guard')?->id ?: $request->ip()) : Limit::none();
        });

        // A11: current-password-verify surface on PUT/PATCH /user* — 5/min
        // keyed by user id (falls back to IP) to bound password guessing on
        // a hijacked session.
        RateLimiter::for('user-mutations', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });
    }
}
