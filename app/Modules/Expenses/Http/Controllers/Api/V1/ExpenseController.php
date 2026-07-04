<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\Requests\StoreExpenseRequest;
use App\Modules\Expenses\Http\Requests\UpdateExpenseRequest;
use App\Modules\Expenses\Http\Resources\ExpenseCollection;
use App\Modules\Expenses\Http\Resources\ExpenseResource;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Repositories\ExpenseRepositoryInterface;
use App\Modules\Expenses\Services\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Expense management REST endpoints.
 *
 * Thin controller — every business rule lives in ExpenseService.
 * This class only: authorizes, resolves inputs, calls the service/repo,
 * and returns the right HTTP response.
 *
 * The update() method covers both plain edits AND the verification path:
 *   PATCH /{expense} with { is_verified: true, ...correctedFields }
 *   confirms a draft in a single call. No separate /verify endpoint needed.
 *
 * Route-model binding:
 *   Laravel resolves {expense} via the BelongsToTenant global scope, so any
 *   id belonging to a different tenant naturally 404s before we get here.
 */
final class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenseService,
        private readonly ExpenseRepositoryInterface $expenses,
    ) {}

    /**
     * Paginated list of expenses with period total for the filtered set.
     *
     * Query params:
     *   ?expense_category_id= — filter to a specific category
     *   ?branch_id=           — filter to a specific branch
     *   ?date_from=           — ISO date lower bound on expense_date
     *   ?date_to=             — ISO date upper bound on expense_date
     *   ?month=               — YYYY-MM shorthand (expands to date_from/to for that month)
     *   ?ocr_status=          — one of: none, pending, processing, done, failed
     *   ?is_verified=         — true/false (0/1 accepted)
     *   ?search=              — LIKE match on vendor or description
     *   ?per_page=            — page size (1–100, default 20)
     */
    public function index(Request $request): ExpenseCollection
    {
        $this->authorize('viewAny', Expense::class);

        $filters = [
            'expense_category_id' => $request->query('expense_category_id'),
            'branch_id' => $request->query('branch_id'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'month' => $request->query('month'),
            'ocr_status' => $request->query('ocr_status'),
            'is_verified' => $request->query('is_verified'),
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', '20'),
        ];

        $paginator = $this->expenses->paginate($filters);

        // Cheap aggregate: sum amount_cents for the full filtered set (not just current page).
        // Passes the same filters but queries the aggregate directly so we get the total
        // across all pages in a single round-trip. The frontend uses this to display
        // the period total without a separate /summary request.
        $periodTotalCents = $this->periodTotal($filters);

        return (new ExpenseCollection($paginator))
            ->additional(['period_total_cents' => $periodTotalCents]);
    }

    /**
     * Monthly expense report: totals grouped by category for a period.
     *
     * Query params:
     *   ?month=YYYY-MM        — the period (defaults to the current month if omitted)
     *   ?date_from= &date_to= — explicit range (used when month is absent)
     *   ?verified_only=       — true to count only confirmed expenses (default: all)
     *
     * Returns { period, total_cents, by_category[] }. Every active category is
     * zero-filled so the chart in S6-E8 has a stable set of slices.
     */
    public function report(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $month = (string) ($request->query('month') ?? now()->format('Y-m'));

        $filters = [
            'month' => $request->query('month') ? $month : null,
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'verified_only' => filter_var($request->query('verified_only', false), FILTER_VALIDATE_BOOL),
        ];

        // Default to the current month when neither month nor an explicit range is given.
        if ($filters['month'] === null && ! $filters['date_from'] && ! $filters['date_to']) {
            $filters['month'] = $month;
        }

        $report = $this->expenses->monthlyReportByCategory($filters);

        return response()->json([
            'data' => [
                'period' => [
                    'month' => $filters['month'],
                    'date_from' => $filters['date_from'],
                    'date_to' => $filters['date_to'],
                ],
                'total_cents' => $report['total_cents'],
                'by_category' => $report['by_category'],
            ],
        ]);
    }

    /**
     * Full expense detail including category, branch, and creator.
     */
    public function show(Expense $expense): ExpenseResource
    {
        $this->authorize('view', $expense);

        $expense->load(['category', 'branch', 'creator']);

        return new ExpenseResource($expense);
    }

    /**
     * Create a manually entered expense for the current tenant.
     *
     * Returns 201 with the full expense shape. The expense is immediately
     * verified (is_verified=true) and has no receipt or OCR pipeline attached.
     */
    public function store(StoreExpenseRequest $request): ExpenseResource|JsonResponse
    {
        $this->authorize('create', Expense::class);

        $expense = $this->expenseService->createManual(
            data: $request->validated(),
            actor: $request->user(),
        );

        $expense->load(['category', 'branch', 'creator']);

        return (new ExpenseResource($expense))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update an expense's fields.
     *
     * Also serves as the verification endpoint: sending { is_verified: true }
     * alongside corrected fields confirms a draft in one atomic write. This
     * avoids a separate /verify action — the intent is clear from the payload.
     */
    public function update(UpdateExpenseRequest $request, Expense $expense): ExpenseResource
    {
        $this->authorize('update', $expense);

        $updated = $this->expenseService->update($expense, $request->validated());

        $updated->load(['category', 'branch', 'creator']);

        return new ExpenseResource($updated);
    }

    /**
     * Soft-delete an expense and remove its receipt file (best-effort).
     *
     * Returns 204 No Content on success.
     */
    public function destroy(Expense $expense): Response
    {
        $this->authorize('delete', $expense);

        Log::info('Expense deletion requested', [
            'expense_id' => $expense->id,
            'tenant_id' => $expense->tenant_id,
        ]);

        $this->expenseService->delete($expense);

        return response()->noContent();
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Sum amount_cents for all expenses matching the given filters (tenant-scoped).
     *
     * Runs a single aggregate query against the already-scoped model — the
     * BelongsToTenant global scope ensures tenant isolation. Returns 0 when
     * no expenses match the filters.
     *
     * @param  array<string, mixed>  $filters
     */
    private function periodTotal(array $filters): int
    {
        $query = Expense::query();

        if (isset($filters['expense_category_id']) && $filters['expense_category_id'] !== '') {
            $query->where('expense_category_id', (int) $filters['expense_category_id']);
        }

        if (isset($filters['branch_id']) && $filters['branch_id'] !== '') {
            $query->where('branch_id', (string) $filters['branch_id']);
        }

        if (isset($filters['month']) && $filters['month'] !== '') {
            $month = (string) $filters['month'];
            $query->whereYear('expense_date', (int) substr($month, 0, 4))
                ->whereMonth('expense_date', (int) substr($month, 5, 2));
        } else {
            if (isset($filters['date_from']) && $filters['date_from'] !== '') {
                $query->whereDate('expense_date', '>=', (string) $filters['date_from']);
            }

            if (isset($filters['date_to']) && $filters['date_to'] !== '') {
                $query->whereDate('expense_date', '<=', (string) $filters['date_to']);
            }
        }

        if (isset($filters['ocr_status']) && $filters['ocr_status'] !== '') {
            $query->where('ocr_status', (string) $filters['ocr_status']);
        }

        if (isset($filters['is_verified']) && $filters['is_verified'] !== '') {
            $query->where('is_verified', (bool) $filters['is_verified']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = (string) $filters['search'];
            $query->where(static function ($q) use ($term): void {
                $q->where('vendor', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }

        return (int) $query->sum('amount_cents');
    }
}
