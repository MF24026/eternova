<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\Requests\StoreReceiptRequest;
use App\Modules\Expenses\Http\Resources\ExpenseResource;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\JsonResponse;

/**
 * Handles receipt-based expense creation and OCR status polling.
 *
 * Two endpoints:
 *   POST /expenses/receipt      — Upload a receipt, create a DRAFT, dispatch OCR job.
 *   GET  /expenses/{expense}/ocr-status — Poll job progress (used by E7 verify UI).
 *
 * Both are intentionally thin: all business logic lives in ExpenseService and
 * ProcessReceiptOcrJob. This controller only resolves inputs, calls the service,
 * and shapes the HTTP response.
 *
 * Authorization is double-layered:
 *   1. StoreReceiptRequest::authorize() — fast-path before validation.
 *   2. Explicit $this->authorize() call in the controller — defence in depth.
 */
final class ReceiptUploadController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
    ) {}

    /**
     * Upload a receipt, create a DRAFT expense, and dispatch the OCR job.
     *
     * Returns 201 immediately. OCR processing is async — the caller should
     * poll GET /{expense}/ocr-status until ocr_status is 'done' or 'failed'.
     */
    public function store(StoreReceiptRequest $request): JsonResponse
    {
        $this->authorize('create', Expense::class);

        $branch = $request->filled('branch_id')
            ? Branch::findOrFail($request->validated('branch_id'))
            : null;

        $expense = $this->expenseService->createFromReceiptUpload(
            file:   $request->file('receipt'),
            branch: $branch,
            actor:  $request->user(),
        );

        return (new ExpenseResource($expense))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Return the current OCR status and extracted data for a single expense.
     *
     * The E7 verification UI polls this endpoint after uploading a receipt to
     * know when OCR suggestions are ready to display. Clients should stop
     * polling when ocr_status is 'done' or 'failed'.
     *
     * Route-model binding resolves {expense} through the BelongsToTenant global
     * scope, so a cross-tenant expense ID returns 404 before reaching this method.
     */
    public function ocrStatus(Expense $expense): JsonResponse
    {
        $this->authorize('view', $expense);

        return response()->json([
            'data' => [
                'ocr_status' => $expense->ocr_status,
                'ocr_data'   => $expense->ocr_data,
            ],
        ]);
    }
}
