<?php

declare(strict_types=1);

use App\Modules\POS\Http\Controllers\Api\V1\PosController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| POS API Routes — /api/v1/pos/*
|--------------------------------------------------------------------------
| Point of sale endpoints: product grid, atomic checkout, and receipt retrieval.
| All routes require Sanctum auth + resolved tenant context.
*/

Route::middleware(['auth:sanctum', 'tenant'])
    ->prefix('pos')
    ->group(static function (): void {
        // Product grid with per-branch exact stock quantities
        Route::get('products', [PosController::class, 'products']);

        // Atomic checkout: creates order + deducts inventory in a transaction
        Route::post('checkout', [PosController::class, 'checkout']);

        // Receipt data for a completed order
        Route::get('orders/{order}/receipt', [PosController::class, 'receipt']);
    });
