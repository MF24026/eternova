<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the reservation settings update endpoint.
 *
 * deposit_pct must be an integer between 0 and 100 inclusive.
 * A value of 0 means no deposit is required by default (staff can still
 * set a per-reservation override). A value of 100 means the full amount
 * is required before confirmation.
 *
 * occasions is a nullable array of non-empty strings. When null (or absent),
 * the system reverts to the DEFAULT_OCCASIONS list. When provided, each entry
 * must be a non-empty string so the frontend dropdown is never broken.
 */
final class UpdateReservationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller calls $this->authorize('manageSettings', Reservation::class) separately.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'deposit_pct'   => ['required', 'integer', 'min:0', 'max:100'],
            'occasions'     => ['nullable', 'array', 'max:30'],
            'occasions.*'   => ['required', 'string', 'min:1', 'max:100'],
        ];
    }
}
