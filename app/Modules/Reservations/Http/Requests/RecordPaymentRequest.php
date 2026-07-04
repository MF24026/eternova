<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for recording a partial payment against a reservation.
 *
 * amount_cents must be a positive integer (no zero-value payments).
 * The overpayment guard (amount would exceed the remaining balance) is
 * enforced by ReservationService::recordPayment() — not here.
 */
final class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller calls $this->authorize('update', $reservation) separately.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_cents' => ['required', 'integer', 'min:1'],
            'payment_method' => ['required', 'string', 'in:cash,card,transfer,other'],
            'reference' => ['nullable', 'string', 'max:255'],
            'paid_at' => ['nullable', 'date'],
        ];
    }
}
