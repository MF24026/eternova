<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Http\Resources\Api\V1\BaseCollection;
use Illuminate\Http\Request;

/**
 * Paginated collection of orders.
 *
 * The index endpoint augments the standard pagination meta with a
 * status_counts object so the frontend can render tab counters without a
 * separate round-trip. Counts are injected via ->additional() in the controller.
 *
 * @see OrderController::index()
 */
final class OrderCollection extends BaseCollection
{
    public $collects = OrderResource::class;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
