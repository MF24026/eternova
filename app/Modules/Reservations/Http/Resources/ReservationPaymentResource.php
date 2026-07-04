<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Reservations\Models\ReservationPayment;
use Illuminate\Http\Request;

/**
 * Transforms a single ReservationPayment for API output.
 *
 * amount_cents is exposed as a raw centavo integer — consistent with the
 * project-wide convention. Formatting for display is the frontend's
 * responsibility via useFormatCurrency.
 *
 * @property ReservationPayment $resource
 */
final class ReservationPaymentResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var ReservationPayment $payment */
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'amount_cents' => $payment->amount_cents,
            'payment_method' => $payment->payment_method,
            'reference' => $payment->reference,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'recorder' => $this->when(
                $payment->relationLoaded('recorder') && $payment->recorder !== null,
                static fn () => [
                    'id' => $payment->recorder?->id,
                    'name' => $payment->recorder?->name,
                ],
            ),
        ];
    }
}
