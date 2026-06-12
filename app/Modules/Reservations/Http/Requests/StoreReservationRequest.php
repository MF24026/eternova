<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the reservation capture endpoint.
 *
 * Validates the required description + total and all optional fields.
 * The deposit_required_cents override is intentionally permissive (any non-negative
 * integer) — the service layer enforces the relationship between the deposit
 * and the tenant default. occasion is a free-form nullable string in v1; future
 * versions may validate against tenant.reservation_occasions when the tenant
 * restricts the list.
 */
final class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller calls $this->authorize('create', Reservation::class) separately.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'description'            => ['required', 'string', 'max:2000'],
            'occasion'               => ['nullable', 'string', 'max:100'],
            'event_date'             => ['nullable', 'date'],
            'total_cents'            => ['required', 'integer', 'min:0'],
            // Explicit per-reservation deposit override — uses tenant default when absent
            'deposit_required_cents' => ['nullable', 'integer', 'min:0'],
            'customer_id'            => ['nullable', 'integer', 'exists:customers,id'],
            'branch_id'              => ['nullable', 'string', 'exists:branches,id'],
            'special_instructions'   => ['nullable', 'string', 'max:2000'],
            'admin_notes'            => ['nullable', 'string', 'max:2000'],
        ];
    }
}
