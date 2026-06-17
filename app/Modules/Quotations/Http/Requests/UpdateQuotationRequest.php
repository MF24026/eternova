<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Input shape for the quotation update endpoint.
 *
 * Same shape as StoreQuotationRequest — items are required because updates
 * replace all line items wholesale (replace-all semantics). This avoids
 * partial-update edge cases where the caller might accidentally delete items
 * by omitting them from a PATCH payload.
 *
 * The service layer enforces that only draft quotations may be updated;
 * attempting to update a non-draft returns 422 from the service.
 */
final class UpdateQuotationRequest extends FormRequest
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
            'customer_id'    => ['nullable', 'integer', tenant_exists('customers')],
            'branch_id'      => ['nullable', 'string', tenant_exists('branches')],
            'issue_date'     => ['required', 'date'],
            'valid_until'    => ['nullable', 'date', 'after_or_equal:issue_date'],
            'discount_cents' => ['nullable', 'integer', 'min:0'],
            'tax_rate_bps'   => ['nullable', 'integer', 'min:0', 'max:9999'],
            'notes'          => ['nullable', 'string', 'max:5000'],
            'terms'          => ['nullable', 'string', 'max:10000'],

            'items'                      => ['required', 'array', 'min:1'],
            'items.*.description'        => ['required', 'string', 'max:500'],
            'items.*.quantity'           => ['required', 'integer', 'min:1'],
            'items.*.unit_price_cents'   => ['required', 'integer', 'min:0'],
            'items.*.product_id'         => ['nullable', 'integer', tenant_exists('products')],
            'items.*.sort_order'         => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'issue_date.required'             => 'La fecha de emisión es obligatoria.',
            'issue_date.date'                 => 'La fecha de emisión no tiene un formato válido.',
            'valid_until.date'                => 'La fecha de vigencia no tiene un formato válido.',
            'valid_until.after_or_equal'      => 'La fecha de vigencia debe ser igual o posterior a la fecha de emisión.',
            'items.required'                  => 'Debe incluir al menos un ítem en la cotización.',
            'items.min'                       => 'Debe incluir al menos un ítem en la cotización.',
            'items.*.description.required'    => 'La descripción de cada ítem es obligatoria.',
            'items.*.quantity.required'       => 'La cantidad de cada ítem es obligatoria.',
            'items.*.quantity.min'            => 'La cantidad debe ser al menos 1.',
            'items.*.unit_price_cents.required' => 'El precio unitario de cada ítem es obligatorio.',
            'items.*.unit_price_cents.min'    => 'El precio unitario no puede ser negativo.',
            'discount_cents.min'              => 'El descuento no puede ser negativo.',
            'tax_rate_bps.min'                => 'La tasa de impuesto no puede ser negativa.',
            'tax_rate_bps.max'                => 'La tasa de impuesto no puede superar 9999 puntos base (99.99%).',
        ];
    }
}
