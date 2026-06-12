<?php

declare(strict_types=1);

use App\Modules\Expenses\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Modules\Expenses\Http\Controllers\Api\V1\ExpenseController;
use App\Modules\Expenses\Http\Controllers\Api\V1\ReceiptUploadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Expenses API Routes — /api/v1/expenses/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
|
| Route ordering: STATIC segments ('categories', 'receipt') MUST appear
| BEFORE the {expense} wildcard to prevent Laravel from trying to resolve
| 'categories' or 'receipt' as an Expense model ID.
|
| E3 routes (keep):
|   POST   /expenses/receipt                — Upload receipt, create DRAFT, dispatch OCR.
|   GET    /expenses/{expense}/ocr-status   — Poll OCR job progress.
|
| E4 routes (new):
|   GET    /expenses/categories             — List tenant's categories (staff).
|   POST   /expenses/categories             — Create category (owner|admin).
|   PATCH  /expenses/categories/{category}  — Update category (owner|admin).
|   DELETE /expenses/categories/{category}  — Delete category (owner|admin; guarded).
|   GET    /expenses                        — Paginated list with filters + period total.
|   POST   /expenses                        — Create manual expense.
|   GET    /expenses/{expense}              — Single expense detail.
|   PATCH  /expenses/{expense}              — Update/verify expense.
|   DELETE /expenses/{expense}              — Soft-delete expense.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('expenses')->group(static function (): void {
    // ── Static sub-paths — MUST appear before the {expense} wildcard ────────

    // E3: receipt upload (keep)
    Route::post('/receipt', [ReceiptUploadController::class, 'store'])
        ->name('api.v1.expenses.receipt.store');

    // E4: category management
    Route::get('/categories', [ExpenseCategoryController::class, 'index'])
        ->name('api.v1.expenses.categories.index');

    Route::post('/categories', [ExpenseCategoryController::class, 'store'])
        ->name('api.v1.expenses.categories.store');

    Route::patch('/categories/{category}', [ExpenseCategoryController::class, 'update'])
        ->name('api.v1.expenses.categories.update');

    Route::delete('/categories/{category}', [ExpenseCategoryController::class, 'destroy'])
        ->name('api.v1.expenses.categories.destroy');

    // ── Collection + manual creation ────────────────────────────────────────
    Route::get('/', [ExpenseController::class, 'index'])
        ->name('api.v1.expenses.index');

    Route::post('/', [ExpenseController::class, 'store'])
        ->name('api.v1.expenses.store');

    // ── Per-expense sub-actions — static paths above; wildcard routes below ─

    // E3: OCR status polling (keep — declared before show to avoid {expense} ambiguity)
    Route::get('/{expense}/ocr-status', [ReceiptUploadController::class, 'ocrStatus'])
        ->name('api.v1.expenses.ocr-status');

    // E4: single-resource CRUD
    Route::get('/{expense}', [ExpenseController::class, 'show'])
        ->name('api.v1.expenses.show');

    Route::patch('/{expense}', [ExpenseController::class, 'update'])
        ->name('api.v1.expenses.update');

    Route::delete('/{expense}', [ExpenseController::class, 'destroy'])
        ->name('api.v1.expenses.destroy');
});
