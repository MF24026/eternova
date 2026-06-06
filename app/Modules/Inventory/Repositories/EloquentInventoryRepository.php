<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentInventoryRepository implements InventoryRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<BranchInventory>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(1, min(100, $perPage));

        $query = BranchInventory::query()
            ->with(['branch', 'productVariant.product']);

        if (isset($filters['branch_id']) && $filters['branch_id'] !== '') {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (isset($filters['product_variant_id']) && $filters['product_variant_id'] !== '') {
            $query->where('product_variant_id', (int) $filters['product_variant_id']);
        }

        if (isset($filters['low_stock']) && $filters['low_stock'] === 'true') {
            // Rows where available drops to zero or below
            $query->where('available', '<=', 0);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $search = (string) $filters['search'];
            $query->whereHas('productVariant.product', static function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('branch_id')->orderBy('product_variant_id')->paginate($perPage);
    }

    public function findWithRelations(int $id): ?BranchInventory
    {
        return BranchInventory::with(['branch', 'productVariant.product'])
            ->find($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryMovement>
     */
    public function paginateMovements(array $filters): LengthAwarePaginator
    {
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $perPage = max(1, min(100, $perPage));

        $query = InventoryMovement::query()
            ->with(['branch', 'productVariant', 'user']);

        if (isset($filters['branch_id']) && $filters['branch_id'] !== '') {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (isset($filters['product_variant_id']) && $filters['product_variant_id'] !== '') {
            $query->where('product_variant_id', (int) $filters['product_variant_id']);
        }

        if (isset($filters['type']) && $filters['type'] !== '') {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['reference_type']) && $filters['reference_type'] !== '') {
            $query->where('reference_type', $filters['reference_type']);
        }

        if (isset($filters['from_date']) && $filters['from_date'] !== '') {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date']) && $filters['to_date'] !== '') {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }
}
