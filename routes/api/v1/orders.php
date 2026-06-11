<?php

declare(strict_types=1);

use App\Modules\Orders\Http\Controllers\Api\V1\OrderController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Orders API Routes — /api/v1/orders/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
| Order management, status transitions, assignee updates, and cancellation.
|
| Route ordering: static segments (none currently, all under {order}) must
| appear before the {order} wildcard. Keep sub-actions as nested paths on
| the wildcard resource: /{order}/status, /{order}/assignee, /{order}/cancel.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('orders')->group(static function (): void {
    Route::get('/', [OrderController::class, 'index'])
        ->name('api.v1.orders.index');

    Route::patch('/{order}/status', [OrderController::class, 'transition'])
        ->name('api.v1.orders.transition');

    Route::patch('/{order}/assignee', [OrderController::class, 'assign'])
        ->name('api.v1.orders.assign');

    Route::post('/{order}/cancel', [OrderController::class, 'cancel'])
        ->name('api.v1.orders.cancel');

    // Static sub-paths above the {order} wildcard; show at the end to avoid
    // shadowing future static segments added under /orders/*.
    Route::get('/{order}', [OrderController::class, 'show'])
        ->name('api.v1.orders.show');
});
