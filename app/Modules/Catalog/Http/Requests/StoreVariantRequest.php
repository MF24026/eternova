<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

final class StoreVariantRequest extends FormRequest
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
            'sku' => ['nullable', 'string', 'max:191'],
            'barcode' => ['nullable', 'string', 'max:100'],
            'price_cents' => ['nullable', 'integer', 'min:0'],
            'cost_price_cents' => ['nullable', 'integer', 'min:0'],
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'options' => ['nullable', 'array'],
            'image_url' => ['nullable', 'url', 'max:500'],
            'position' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
