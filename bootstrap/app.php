<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Tenancy\Http\Middleware\EnsureTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AddLinkHeadersForPreloadedAssets::class,
            HandleInertiaRequests::class,
        ]);

        // Register alias — apply to tenant-scoped routes when ready
        $middleware->alias([
            'tenant' => EnsureTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
