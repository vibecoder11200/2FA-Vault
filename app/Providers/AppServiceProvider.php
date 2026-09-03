<?php

namespace App\Providers;

use App\Facades\Settings;
use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Console\ClientCommand;
use Laravel\Passport\Console\InstallCommand;
use Laravel\Passport\Console\KeysCommand;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        URL::forceRootUrl(config('app.url'));

        // Limited to 191 to prevent index length issue with MyISAM and utf8mb4_unicode_ci
        // when using WAMP (WAMP uses MyISAM as default engine in place of INNOdb)
        Schema::defaultStringLength(191);

        JsonResource::withoutWrapping();

        // Passport v13 defaults to UUID client IDs. The fork's oauth_clients
        // schema uses integer IDs, so we opt out to keep backward compatibility.
        Passport::$clientUuids = false;
        // Passport v13 validates RSA key file permissions on Unix; the Windows
        // dev environment does not use the same permission model, so disable
        // the check to avoid false positives.
        Passport::$validateKeyPermissions = false;

        // PAT scopes (A6). New personal access tokens must carry at least one
        // of these scopes (enforced by PersonalAccessTokenController::store()
        // and the `pat.scopes` route middleware). Pre-cutover tokens were
        // stamped with the `legacy_full_access` marker by a one-time
        // migration; ONLY that explicit marker grants full access — a plain
        // empty scope set is NOT trusted (Passport defaults omitted scopes
        // to [], which would otherwise allow minting "legacy" tokens).
        Passport::tokensCan([
            'read'  => 'Read accounts, groups, preferences and other vault data',
            'otp'   => 'Generate one-time passwords and read OTP-related data',
            'write' => 'Create, update and delete vault data and preferences',
            'admin' => 'Perform administrative operations',
        ]);

        // Personal access tokens no longer live for a year by default.
        // Eager form: this Passport version's signature does not accept a
        // closure, and the app boots per-request (PHP-FPM), so boot-time
        // evaluation is fine. Revisit if moving to Octane/long-lived workers.
        Passport::personalAccessTokensExpireIn(now()->addDays(90));

        $this->commands([
            InstallCommand::class,
            ClientCommand::class,
            KeysCommand::class,
        ]);

        Gate::before(function (User $user, string $ability) {
            if ($user->isAdministrator()) {
                return true;
            }
        });

        Gate::define('manage-pat', function (User $user) {
            $useSsoOnly = Settings::get('useSsoOnly');

            return ($useSsoOnly && Settings::get('allowPatWhileSsoOnly')) || $useSsoOnly !== true;
        });

        Gate::define('manage-webauthn-credentials', function (User $user) {
            return ! Settings::get('useSsoOnly');
        });

        $this->registerWebDavDriver();
    }

    /**
     * Register the WebDAV Flysystem v3 driver for auto-backup destinations.
     * The adapter package (league/flysystem-webdav) must be installed via composer.
     *
     * PHP 8.4 note: sabre/dav 4.x (a transitive dependency) emits
     * E_DEPRECATED warnings about implicitly nullable parameter types from its
     * server-side classes (CalDAV/CardDAV/DAVACL/Server tree nodes). This
     * project only uses \Sabre\DAV\Client (the WebDAV *client*), so the
     * deprecated code paths are never loaded and the warnings do not fire in
     * practice. Bumping sabre/dav to 5.x would clear them upstream but is
     * currently blocked by league/flysystem-webdav's ^4.6.0 constraint.
     */
    private function registerWebDavDriver() : void
    {
        Storage::extend('webdav', function ($app, array $config) {
            $client = new \Sabre\DAV\Client([
                'baseUri'  => rtrim($config['baseUri'], '/') . '/',
                'userName' => $config['userName'] ?? '',
                'password' => $config['password'] ?? '',
            ]);

            // C11: bound request timings so a hung WebDAV endpoint cannot
            // burn the whole auto-backup job timeout.
            $client->addCurlSetting(CURLOPT_TIMEOUT, (int) ($config['timeout'] ?? 60));
            $client->addCurlSetting(CURLOPT_CONNECTTIMEOUT, (int) ($config['connect_timeout'] ?? 10));

            $adapter = new \League\Flysystem\WebDAV\WebDAVAdapter(
                $client,
                $config['pathPrefix'] ?? ''
            );

            return new \League\Flysystem\Filesystem($adapter);
        });
    }
}
