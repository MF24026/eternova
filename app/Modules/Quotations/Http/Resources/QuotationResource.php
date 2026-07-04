<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use Illuminate\Http\Request;

/**
 * Transforms a single Quotation for API output.
 *
 * Monetary values (subtotal_cents, discount_cents, tax_cents, total_cents)
 * are exposed as raw centavo integers — consistent with the Orders and
 * Reservations module convention. Formatting for display (currency symbol,
 * decimal places) is the frontend's responsibility via useFormatCurrency.
 *
 * tax_rate_bps is exposed raw (e.g. 1300 = 13%) so the frontend can display
 * it as a formatted percentage without server round-trips.
 *
 * allowed_transitions is resolved at serialisation time so the frontend can
 * render only the valid action buttons without knowing the state machine.
 *
 * Nested relations are conditionally included only when explicitly loaded —
 * prevents accidental N+1 if the caller forgets to eager-load.
 *
 * QuotationService is resolved from the container at serialisation time rather
 * than injected via constructor — ResourceCollection uses mapInto() to instantiate
 * resources which calls new QuotationResource($model), making a custom constructor
 * signature unsafe (it would receive the collection index as the second argument).
 *
 * @property Quotation $resource
 */
final class QuotationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Quotation $quotation */
        $quotation = $this->resource;

        $service = app(QuotationService::class);

        return [
            'id' => $quotation->id,
            'quotation_number' => $quotation->quotation_number,
            'status' => $quotation->status,
            'issue_date' => $quotation->issue_date?->toDateString(),
            'valid_until' => $quotation->valid_until?->toDateString(),
            'subtotal_cents' => $quotation->subtotal_cents,
            'discount_cents' => $quotation->discount_cents,
            'tax_rate_bps' => $quotation->tax_rate_bps,
            'tax_cents' => $quotation->tax_cents,
            'total_cents' => $quotation->total_cents,
            'notes' => $quotation->notes,
            'terms' => $quotation->terms,
            'branch_id' => $quotation->branch_id,
            'created_by' => $quotation->created_by,
            'converted_order_id' => $quotation->converted_order_id,
            'allowed_transitions' => $service->allowedTransitions($quotation),
            'created_at' => $quotation->created_at?->toIso8601String(),
            'updated_at' => $quotation->updated_at?->toIso8601String(),

            'customer' => $this->when(
                $quotation->relationLoaded('customer') && $quotation->customer !== null,
                static fn () => [
                    'id' => $quotation->customer?->id,
                    'name' => $quotation->customer?->name,
                ],
            ),

            'branch' => $this->when(
                $quotation->relationLoaded('branch') && $quotation->branch !== null,
                static fn () => [
                    'id' => $quotation->branch?->id,
                    'name' => $quotation->branch?->name,
                ],
            ),

            'creator' => $this->when(
                $quotation->relationLoaded('creator') && $quotation->creator !== null,
                static fn () => [
                    'id' => $quotation->creator?->id,
                    'name' => $quotation->creator?->name,
                ],
            ),

            'items' => $this->when(
                $quotation->relationLoaded('items'),
                fn () => QuotationItemResource::collection($quotation->items),
            ),

            'status_history' => $this->when(
                $quotation->relationLoaded('statusHistory'),
                fn () => QuotationStatusHistoryResource::collection($quotation->statusHistory),
            ),
        ];
    }
}
