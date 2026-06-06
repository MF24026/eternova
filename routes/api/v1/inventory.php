<?php

declare(strict_types=1);

use App\Modules\Inventory\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inventory API Routes — /api/v1/inventory/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
| Stock levels, movements, and transfers endpoints land in Sprint 1.
|
| Route ordering: static segments (movements, transfers) must appear BEFORE
| the {inventory} wildcard so they are not interpreted as an inventory id.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('inventory')->group(static function (): void {
    Route::get('/', [InventoryController::class, 'index'])
        ->name('api.v1.inventory.index');

    Route::get('/movements', [InventoryController::class, 'movements'])
        ->name('api.v1.inventory.movements.index');

    Route::post('/movements', [InventoryController::class, 'storeMovement'])
        ->name('api.v1.inventory.movements.store');

    Route::post('/transfers', [InventoryController::class, 'storeTransfer'])
        ->name('api.v1.inventory.transfers.store');

    Route::get('/{inventory}', [InventoryController::class, 'show'])
        ->name('api.v1.inventory.show');
});
