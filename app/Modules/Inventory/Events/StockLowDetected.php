<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Events;

use App\Modules\Inventory\Models\BranchInventory;

/**
 * Fired after a transaction commits that caused available stock to cross
 * below the alert threshold for a variant at a specific branch.
 *
 * "Cross below" means: available was at or above the threshold before the
 * operation, and is now strictly below it. Already-low stock does not
 * re-fire this event to prevent notification spam.
 */
final class StockLowDetected
{
    public function __construct(
        public readonly BranchInventory $inventory,
        public readonly int $threshold,
    ) {}
}
