<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Reservations\Models\ReservationStatusHistory;
use Illuminate\Http\Request;

/**
 * Transforms a single ReservationStatusHistory row for API output.
 *
 * from_status is null for the initial creation entry (the reservation was born
 * directly into to_status). The frontend uses this to render the "Created" pill
 * differently from actual state transitions.
 *
 * @property ReservationStatusHistory $resource
 */
final class ReservationStatusHistoryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var ReservationStatusHistory $entry */
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
