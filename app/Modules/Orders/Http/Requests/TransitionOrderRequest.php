<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the order status transition endpoint.
 *
 * Validates the requested next status and an optional note. The state machine
 * itself (which transitions are valid from the current status) is enforced by
 * OrderService::transitionTo() — not here. This request only guards the input
 * shape so malformed payloads are rejected before reaching the service.
 */
final class TransitionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller calls $this->authorize('update', $order) separately.
        // FormRequest::authorize() is intentionally permissive here — the full
        // policy check runs in the controller after route-model binding resolves.
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
                'in:pending,preparing,ready,dispatched,delivered,cancelled',
            ],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
