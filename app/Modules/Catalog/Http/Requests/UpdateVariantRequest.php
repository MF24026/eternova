<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $product = $this->route('product');

        if (! $product instanceof Product) {
            return false;
        }

        return $this->user()?->can('update', $product) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sku' => ['sometimes', 'string', 'max:191'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'cost_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'weight_grams' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'options' => ['sometimes', 'array'],
            'image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'position' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
