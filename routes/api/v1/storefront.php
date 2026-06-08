<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Api\V1\Storefront\StorefrontCategoryController;
use App\Modules\Catalog\Http\Controllers\Api\V1\Storefront\StorefrontProductController;
use App\Modules\Catalog\Http\Controllers\Api\V1\Storefront\StorefrontTenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Storefront API Routes — /api/v1/storefront/*
|--------------------------------------------------------------------------
| Public read-only catalog endpoints. NO auth:sanctum — these are visible
| to anonymous visitors. Tenant is resolved from the subdomain by the
| 'tenant' middleware (EnsureTenant); a missing or unknown subdomain → 404.
|
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
*/

Route::middleware(['tenant'])->prefix('storefront')->name('api.v1.storefront.')->group(static function (): void {
    // ── Tenant branding ───────────────────────────────────────────────────────
    Route::get('/tenant', [StorefrontTenantController::class, 'show'])
        ->name('tenant.show');

    // ── Categories (tree) ─────────────────────────────────────────────────────
    Route::get('/categories', [StorefrontCategoryController::class, 'index'])
        ->name('categories.index');

    // ── Products ──────────────────────────────────────────────────────────────
    // "featured" must be declared BEFORE {slug} so that "featured" is not
    // interpreted as a product slug.
    Route::get('/products/featured', [StorefrontProductController::class, 'featured'])
        ->name('products.featured');

    Route::get('/products', [StorefrontProductController::class, 'index'])
        ->name('products.index');

    Route::get('/products/{slug}', [StorefrontProductController::class, 'show'])
        ->where('slug', '[a-z0-9\-]+')
        ->name('products.show');
});
