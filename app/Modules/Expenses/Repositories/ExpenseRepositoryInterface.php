<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Repositories;

use App\Modules\Expenses\Models\Expense;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ExpenseRepositoryInterface
{
    /**
     * Persist a new Expense row.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Expense;

    /**
     * Find an expense by its integer id within the current tenant scope.
     * Returns null when not found (caller decides whether to 404 or handle gracefully).
     */
    public function find(int $id): ?Expense;

    /**
     * Paginated list of expenses scoped to the current tenant.
     *
     * Supported filters:
     *   - expense_category_id  int    — limit to a specific category
     *   - branch_id            string — limit to a specific branch
     *   - date_from            string — ISO date, inclusive lower bound on expense_date
     *   - date_to              string — ISO date, inclusive upper bound on expense_date
     *   - month                string — YYYY-MM convenience: expands to date_from/date_to
     *   - ocr_status           string — one of: none, pending, processing, done, failed
     *   - is_verified          bool   — true/false filter on the verified flag
     *   - search               string — LIKE match on vendor or description
     *   - per_page             int    — page size, default 20, max 100
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Expense>
     */
    public function paginate(array $filters): LengthAwarePaginator;
}
