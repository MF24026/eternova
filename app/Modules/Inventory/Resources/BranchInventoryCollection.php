<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Resources;

use App\Http\Resources\Api\V1\BaseCollection;

final class BranchInventoryCollection extends BaseCollection
{
    public $collects = BranchInventoryResource::class;
}
