<?php

namespace Bausteln\SnipeitOidc\Providers;

use Bausteln\SnipeitOidc\Http\Middleware\AutoRedirectToOidc;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class OidcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/oidc.php', 'oidc');
    }

    public function boot(): void
    {
        // Publish config so admins can override per-install
        $this->publishes([
            __DIR__ . '/../../config/oidc.php' => config_path('oidc.php'),
        ], 'oidc-config');

        // Only register routes when the plugin is turned on
        if (config('oidc.enabled') !== true) {
            return;
        }

        Route::middleware('web')
            ->namespace('Bausteln\\SnipeitOidc\\Http\\Controllers')
            ->group(__DIR__ . '/../../routes/web.php');

        $this->loadViewsFrom(__DIR__ . '/../../resources/views', 'oidc');

        // Push onto the `web` middleware group (not the global stack) so the
        // session is started before we run — Auth::check() needs it.
        $this->app->make(Router::class)->pushMiddlewareToGroup('web', AutoRedirectToOidc::class);
    }
}
