<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\Api\V1\CategoryController;
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
});
