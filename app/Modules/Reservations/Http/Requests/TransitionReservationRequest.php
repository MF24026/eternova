<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the reservation status transition endpoint.
 *
 * Validates the requested next status and an optional note.
 * The state machine itself (which transitions are valid from the current status)
 * is enforced by ReservationService — not here. This request only guards the
 * input shape so malformed payloads are rejected before reaching the service.
 *
 * The 'force' flag is used only when status === 'confirmed'. When true, the
 * deposit coverage check is bypassed (admin override). For all other transitions
 * the flag is ignored.
 */
final class TransitionReservationRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                'in:inquiry,confirmed,in_progress,ready,delivered,cancelled',
            ],
            'note' => ['nullable', 'string', 'max:500'],
            'force' => ['nullable', 'boolean'],
        ];
    }
}
