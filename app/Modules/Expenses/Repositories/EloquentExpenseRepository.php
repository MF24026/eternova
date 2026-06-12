<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Repositories;

use App\Modules\Expenses\Models\Expense;
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
}
