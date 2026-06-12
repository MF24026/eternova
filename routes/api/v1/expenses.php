<?php

declare(strict_types=1);

use App\Modules\Expenses\Http\Controllers\Api\V1\ReceiptUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Expenses API Routes — /api/v1/expenses/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
|
| E3 delivers:
|   POST /expenses/receipt            — Upload receipt, create DRAFT, dispatch OCR.
|   GET  /expenses/{expense}/ocr-status — Poll OCR job progress.
|
| Route ordering: static segments ('receipt') are declared BEFORE the
| {expense} wildcard to prevent Laravel from trying to resolve 'receipt'
| as an Expense model ID.
|
| E4 will add the full expense CRUD (index, show, store, update, destroy,
| verify) under this same middleware group.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('expenses')->group(static function (): void {
    // Static sub-paths must appear before the {expense} wildcard.
    Route::post('/receipt', [ReceiptUploadController::class, 'store'])
        ->name('api.v1.expenses.receipt.store');

    Route::get('/{expense}/ocr-status', [ReceiptUploadController::class, 'ocrStatus'])
        ->name('api.v1.expenses.ocr-status');
});
