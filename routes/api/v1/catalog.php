<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Api\V1\CategoryController;
use App\Modules\Catalog\Http\Controllers\Api\V1\ProductController;
use App\Modules\Catalog\Http\Controllers\Api\V1\ProductImageController;
use App\Modules\Catalog\Http\Controllers\Api\V1\ProductVariantController;
use App\Modules\Catalog\Http\Controllers\Api\V1\TagController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Catalog API Routes — /api/v1/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
| Categories, products, tags endpoints land in Sprint 1.
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(static function (): void {
    // ── Categories ───────────────────────────────────────────────────────────
    // Reorder must be declared BEFORE the {category} wildcard route so that
    // "reorder" is not interpreted as a category id.
    Route::patch('/categories/reorder', [CategoryController::class, 'reorder'])
        ->name('api.v1.categories.reorder');

    Route::get('/categories', [CategoryController::class, 'index'])
        ->name('api.v1.categories.index');

    Route::post('/categories', [CategoryController::class, 'store'])
        ->name('api.v1.categories.store');

    Route::get('/categories/{category}', [CategoryController::class, 'show'])
        ->name('api.v1.categories.show');

    Route::patch('/categories/{category}', [CategoryController::class, 'update'])
        ->name('api.v1.categories.update');

    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])
        ->name('api.v1.categories.destroy');

    Route::post('/categories/{category}/restore', [CategoryController::class, 'restore'])
        ->withTrashed()
        ->name('api.v1.categories.restore');

    // ── Products ──────────────────────────────────────────────────────────────
    // Static sub-routes declared BEFORE wildcard {product} routes to prevent
    // "reorder" or "restore" from being captured as a product id.
    Route::post('/products/{product}/restore', [ProductController::class, 'restore'])
        ->withTrashed()
        ->name('api.v1.products.restore');

    // Variant reorder must come BEFORE the {variant} wildcard.
    Route::patch('/products/{product}/variants/reorder', [ProductVariantController::class, 'reorder'])
        ->name('api.v1.products.variants.reorder');

    Route::get('/products', [ProductController::class, 'index'])
        ->name('api.v1.products.index');

    Route::post('/products', [ProductController::class, 'store'])
        ->name('api.v1.products.store');

    Route::get('/products/{product}', [ProductController::class, 'show'])
        ->name('api.v1.products.show');

    Route::patch('/products/{product}', [ProductController::class, 'update'])
        ->name('api.v1.products.update');

    Route::delete('/products/{product}', [ProductController::class, 'destroy'])
        ->name('api.v1.products.destroy');

    // ── Product images sub-resource ───────────────────────────────────────────
    // Static action routes BEFORE {product} wildcard sub-routes.
    Route::patch('/products/{product}/images/reorder', [ProductImageController::class, 'reorder'])
        ->name('api.v1.products.images.reorder');

    Route::patch('/products/{product}/images/set-default', [ProductImageController::class, 'setDefault'])
        ->name('api.v1.products.images.set-default');

    Route::post('/products/{product}/images', [ProductImageController::class, 'store'])
        ->name('api.v1.products.images.store');

    Route::delete('/products/{product}/images', [ProductImageController::class, 'destroy'])
        ->name('api.v1.products.images.destroy');

    // Variant sub-resource
    Route::post('/products/{product}/variants', [ProductVariantController::class, 'store'])
        ->name('api.v1.products.variants.store');

    Route::patch('/products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])
        ->name('api.v1.products.variants.update');

    Route::delete('/products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])
        ->name('api.v1.products.variants.destroy');

    // ── Tags ──────────────────────────────────────────────────────────────────
    Route::get('/tags', [TagController::class, 'index'])
        ->name('api.v1.tags.index');

    Route::post('/tags', [TagController::class, 'store'])
        ->name('api.v1.tags.store');

    Route::patch('/tags/{tag}', [TagController::class, 'update'])
        ->name('api.v1.tags.update');

    Route::delete('/tags/{tag}', [TagController::class, 'destroy'])
        ->name('api.v1.tags.destroy');
});
