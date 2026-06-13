<?php

declare(strict_types=1);

use App\Modules\Quotations\Http\Controllers\Api\V1\QuotationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Quotations API Routes — /api/v1/quotations/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
| Quotation CRUD, status transitions, and soft-delete.
|
| Route ordering: static action segments (send, accept, reject) MUST appear
| before the {quotation} GET show route to prevent Laravel from matching
| "send" as a quotation id. This mirrors the Reservations and Orders modules.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('quotations')->group(static function (): void {
    // ── Collection + create ──────────────────────────────────────────────────
    Route::get('/', [QuotationController::class, 'index'])
        ->name('api.v1.quotations.index');

    Route::post('/', [QuotationController::class, 'store'])
        ->name('api.v1.quotations.store');

    // ── Sub-actions on a single quotation — static BEFORE {quotation} ────────
    Route::post('/{quotation}/send', [QuotationController::class, 'send'])
        ->name('api.v1.quotations.send');

    Route::post('/{quotation}/accept', [QuotationController::class, 'accept'])
        ->name('api.v1.quotations.accept');

    Route::post('/{quotation}/reject', [QuotationController::class, 'reject'])
        ->name('api.v1.quotations.reject');

    // ── Single-resource CRUD — declared after sub-action routes ──────────────
    Route::get('/{quotation}', [QuotationController::class, 'show'])
        ->name('api.v1.quotations.show');

    Route::put('/{quotation}', [QuotationController::class, 'update'])
        ->name('api.v1.quotations.update');

    Route::patch('/{quotation}', [QuotationController::class, 'update'])
        ->name('api.v1.quotations.update.patch');

    Route::delete('/{quotation}', [QuotationController::class, 'destroy'])
        ->name('api.v1.quotations.destroy');
});
