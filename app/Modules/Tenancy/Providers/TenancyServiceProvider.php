<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Providers;

use App\Modules\Tenancy\Http\Middleware\EnsureTenant;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * 'currentTenant' is bound to null by default so that app()->bound('currentTenant')
     * returns true even before middleware runs, preventing unresolved binding exceptions
     * in contexts like CLI commands or jobs that bypass HTTP middleware.
     *
     * Decision: we chose NOT to throw when currentTenant is missing in TenantScope —
     * instead the scope simply doesn't filter, allowing super-admin and CLI full access.
     */
    public function register(): void
    {
        $this->app->bind('currentTenant', fn (): ?Tenant => null);
    }

    /**
     * Bootstrap services and register the middleware alias.
     */
    public function boot(): void
    {
        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $router->aliasMiddleware('tenant', EnsureTenant::class);
    }
}
