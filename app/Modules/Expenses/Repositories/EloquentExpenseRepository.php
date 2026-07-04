<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Repositories;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentExpenseRepository implements ExpenseRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Expense
    {
        return Expense::create($data);
    }

    public function find(int $id): ?Expense
    {
        return Expense::with(['category', 'branch', 'creator'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Expense>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = Expense::with(['category', 'branch', 'creator']);

        if (isset($filters['expense_category_id']) && $filters['expense_category_id'] !== '') {
            $query->where('expense_category_id', (int) $filters['expense_category_id']);
        }

        if (isset($filters['branch_id']) && $filters['branch_id'] !== '') {
            $query->where('branch_id', (string) $filters['branch_id']);
        }

        // month=YYYY-MM is a convenience shorthand that expands to a full date range.
        // It takes precedence over date_from/date_to when present.
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

        return $query->orderByDesc('expense_date')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{total_cents: int, by_category: list<array{category_id: int|null, category_name: string, category_type: string|null, total_cents: int, count: int}>}
     */
    public function monthlyReportByCategory(array $filters): array
    {
        $query = Expense::query();

        // month=YYYY-MM takes precedence over an explicit date range.
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

        if (! empty($filters['verified_only'])) {
            $query->where('is_verified', true);
        }

        // One aggregate query: total + count per category id. Keyed by the integer
        // category id; uncategorised rows (NULL) are pulled out separately below.
        $aggRows = $query
            ->selectRaw('expense_category_id, SUM(amount_cents) as total, COUNT(*) as cnt')
            ->groupBy('expense_category_id')
            ->get();

        /** @var array<int, array{total: int, count: int}> $byId */
        $byId = [];
        $uncategorised = ['total' => 0, 'count' => 0];
        $grandTotal = 0;

        foreach ($aggRows as $row) {
            $total = (int) $row->total;
            $grandTotal += $total;

            if ($row->expense_category_id === null) {
                $uncategorised = ['total' => $total, 'count' => (int) $row->cnt];

                continue;
            }

            $byId[(int) $row->expense_category_id] = ['total' => $total, 'count' => (int) $row->cnt];
        }

        $rows = [];

        // Include every active category (zero-filled) plus any inactive category
        // that still has expenses in the period — so retired categories with history
        // are never silently dropped from the totals.
        $categories = ExpenseCategory::orderBy('name')->get();

        foreach ($categories as $category) {
            $agg = $byId[$category->id] ?? null;

            if ($agg === null && ! $category->is_active) {
                continue; // inactive + no expenses → omit
            }

            $rows[] = [
                'category_id' => $category->id,
                'category_name' => $category->name,
                'category_type' => $category->type,
                'total_cents' => $agg['total'] ?? 0,
                'count' => $agg['count'] ?? 0,
            ];
        }

        if ($uncategorised['count'] > 0) {
            $rows[] = [
                'category_id' => null,
                'category_name' => 'Sin categoria',
                'category_type' => null,
                'total_cents' => $uncategorised['total'],
                'count' => $uncategorised['count'],
            ];
        }

        return [
            'total_cents' => $grandTotal,
            'by_category' => $rows,
        ];
    }
}
