<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the quotation status transition endpoints (send, accept, reject).
 *
 * Only validates the optional note — the target status is implicit from the
 * route (POST /send, POST /accept, POST /reject) and does not need to come
 * from the request body. The state machine itself is enforced by QuotationService.
 */
final class TransitionQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Controller calls $this->authorize('update', $quotation) separately.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
