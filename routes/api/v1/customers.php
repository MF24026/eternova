<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\Api\V1\CustomerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customers API Routes — /api/v1/*
|--------------------------------------------------------------------------
| All routes here are prefixed with /api/v1 by bootstrap/app.php.
| The /restore sub-route is declared BEFORE the {customer} wildcard so that
| "restore" is not interpreted as a customer id.
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(static function (): void {
    Route::post('/customers/{customer}/restore', [CustomerController::class, 'restore'])
        ->withTrashed()
        ->name('api.v1.customers.restore');

    Route::get('/customers', [CustomerController::class, 'index'])
        ->name('api.v1.customers.index');

    Route::post('/customers', [CustomerController::class, 'store'])
        ->name('api.v1.customers.store');

    Route::get('/customers/{customer}', [CustomerController::class, 'show'])
        ->name('api.v1.customers.show');

    Route::patch('/customers/{customer}', [CustomerController::class, 'update'])
        ->name('api.v1.customers.update');

    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])
        ->name('api.v1.customers.destroy');
});
