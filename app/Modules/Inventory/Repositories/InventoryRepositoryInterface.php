<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Repositories;

use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InventoryRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<BranchInventory>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    public function findWithRelations(int $id): ?BranchInventory;

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<InventoryMovement>
     */
    public function paginateMovements(array $filters): LengthAwarePaginator;
}
