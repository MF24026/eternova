<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\RobotsController;
use App\Modules\Catalog\Http\Controllers\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenant server-rendered routes (sitemap + robots)
|--------------------------------------------------------------------------
| These routes are declared BEFORE the SPA catch-all so Laravel handles
| them directly rather than letting the SPA pick them up.
|
| Both use the 'tenant' middleware so EnsureTenant resolves the tenant
| from the subdomain. No auth required — storefront is fully public.
*/

Route::middleware('tenant')->group(function (): void {
    Route::get('/sitemap.xml', [SitemapController::class, 'index'])
        ->name('storefront.sitemap');

    Route::get('/robots.txt', [RobotsController::class, 'index'])
        ->name('storefront.robots');
});

/*
|--------------------------------------------------------------------------
| SPA Catch-All
|--------------------------------------------------------------------------
| All web routes (except /api/*) are handled by the Vue 3 SPA.
| Vue Router controls navigation client-side after the initial load.
|
| The old Inertia-based routes (/admin/*, /catalog/*, /product/*, etc.)
| are intentionally removed. They will be handled by Vue Router in the SPA.
| Backend API routes live under /api/v1/* (see routes/api/).
| Legacy Inertia pages in resources/js/Pages/ (capital P) remain dormant
| until issue #12 migrates or removes them.
*/

Route::get('/{any?}', fn () => view('app'))
    ->where('any', '^(?!api).*$')
    ->name('spa');
