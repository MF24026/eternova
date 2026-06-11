<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Http\Request;

/**
 * Transforms a single OrderStatusHistory row for API output.
 *
 * from_status is null for the initial creation entry (the order was born
 * directly into to_status). Callers can detect assignment-only events by
 * checking from_status === to_status (see OrderService::assign()).
 *
 * @property OrderStatusHistory $resource
 */
final class OrderStatusHistoryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var OrderStatusHistory $entry */
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'from_status' => $entry->from_status,
            'to_status' => $entry->to_status,
            'note' => $entry->note,
            'user' => $this->when(
                $entry->relationLoaded('user') && $entry->user !== null,
                static fn () => [
                    'id' => $entry->user?->id,
                    'name' => $entry->user?->name,
                ],
            ),
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
