<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Http\Resources\Api\V1\BaseCollection;

final class InventoryMovementCollection extends BaseCollection
{
    public $collects = InventoryMovementResource::class;
}
