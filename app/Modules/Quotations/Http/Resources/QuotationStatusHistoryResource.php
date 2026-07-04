<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Quotations\Models\QuotationStatusHistory;
use Illuminate\Http\Request;

/**
 * Transforms a single QuotationStatusHistory row for API output.
 *
 * from_status is null for the initial creation entry (the quotation was born
 * directly into to_status). The frontend uses this to render the "Created" pill
 * differently from actual state transitions.
 *
 * @property QuotationStatusHistory $resource
 */
final class QuotationStatusHistoryResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var QuotationStatusHistory $entry */
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
