<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Services\ReservationService;
use Illuminate\Http\Request;

/**
 * Transforms a single Reservation for API output.
 *
 * Monetary values (total_cents, deposit_required_cents, deposit_paid_cents,
 * balance_cents, deposit_outstanding_cents) are exposed as raw centavo integers —
 * consistent with the Orders module convention. Formatting for display (currency
 * symbol, decimal places) is the frontend's responsibility via useFormatCurrency.
 *
 * allowed_transitions is resolved at serialisation time so the frontend can
 * render only the valid action buttons without knowing the state machine.
 *
 * Nested relations (branch, customer, assignee, creator, payments, statusHistory)
 * are conditionally included only when explicitly loaded — prevents accidental N+1
 * if the caller forgets to eager-load.
 *
 * ReservationService is resolved from the container at serialisation time rather
 * than injected via constructor. ResourceCollection uses mapInto() to instantiate
 * resources, which calls new ReservationResource($model) — a custom constructor
 * signature would receive the collection index as the second argument and throw.
 *
 * @property Reservation $resource
 */
final class ReservationResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Reservation $reservation */
        $reservation = $this->resource;

        $service = app(ReservationService::class);

        return [
            'id'                     => $reservation->id,
            'reservation_number'     => $reservation->reservation_number,
            'status'                 => $reservation->status,
            'occasion'               => $reservation->occasion,
            'description'            => $reservation->description,
            'event_date'             => $reservation->event_date?->toDateString(),
            'total_cents'            => $reservation->total_cents,
            'deposit_required_cents' => $reservation->deposit_required_cents,
            'deposit_paid_cents'     => $reservation->deposit_paid_cents,
            // Computed: how much the customer still owes on the full total
            'balance_cents'          => $reservation->remainingBalanceCents(),
            // Computed: how much of the required deposit is still unpaid
            'deposit_outstanding_cents' => $reservation->depositOutstandingCents(),
            'special_instructions'   => $reservation->special_instructions,
            'admin_notes'            => $reservation->admin_notes,
            'converted_order_id'     => $reservation->converted_order_id,
            'allowed_transitions'    => $service->allowedTransitions($reservation),
            'created_at'             => $reservation->created_at?->toIso8601String(),
            'updated_at'             => $reservation->updated_at?->toIso8601String(),

            'branch' => $this->when(
                $reservation->relationLoaded('branch') && $reservation->branch !== null,
                static fn () => [
                    'id'   => $reservation->branch?->id,
                    'name' => $reservation->branch?->name,
                ],
            ),

            'customer' => $this->when(
                $reservation->relationLoaded('customer') && $reservation->customer !== null,
                static fn () => [
                    'id'    => $reservation->customer?->id,
                    'name'  => $reservation->customer?->name,
                    'phone' => $reservation->customer?->phone,
                ],
            ),

            'assignee' => $this->when(
                $reservation->relationLoaded('assignee'),
                static fn () => $reservation->assignee !== null
                    ? ['id' => $reservation->assignee->id, 'name' => $reservation->assignee->name]
                    : null,
            ),

            'creator' => $this->when(
                $reservation->relationLoaded('creator') && $reservation->creator !== null,
                static fn () => [
                    'id'   => $reservation->creator?->id,
                    'name' => $reservation->creator?->name,
                ],
            ),

            'payments' => $this->when(
                $reservation->relationLoaded('payments'),
                fn () => ReservationPaymentResource::collection($reservation->payments),
            ),

            'status_history' => $this->when(
                $reservation->relationLoaded('statusHistory'),
                fn () => ReservationStatusHistoryResource::collection($reservation->statusHistory),
            ),
        ];
    }
}
