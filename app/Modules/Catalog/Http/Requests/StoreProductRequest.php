<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Support\SlugValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Product::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = current_tenant()?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && ! SlugValidator::isValid((string) $value)) {
                        $fail('The slug must be a lowercase alphanumeric slug (RFC 1035).');
                    }
                },
                Rule::unique('products', 'slug')
                    ->where('tenant_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'description' => ['nullable', 'string', 'max:10000'],
            'sku_root' => ['nullable', 'string', 'max:100'],
            'base_price_cents' => ['required', 'integer', 'min:0'],
            'cost_price_cents' => ['nullable', 'integer', 'min:0'],
            'default_image_url' => ['nullable', 'url', 'max:500'],
            'gallery' => ['nullable', 'array'],
            'gallery.*' => ['string', 'url', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],

            // Options: define the product's configurable dimensions
            'options' => ['nullable', 'array'],
            'options.*.name' => ['required_with:options', 'string', 'max:100'],
            'options.*.values' => ['required_with:options', 'array', 'min:1'],
            'options.*.values.*' => ['string', 'max:100'],

            // Explicit variants: overrides matrix generation when provided
            'variants' => ['nullable', 'array'],
            'variants.*.sku' => ['nullable', 'string', 'max:191'],
            'variants.*.price_cents' => ['nullable', 'integer', 'min:0'],
            'variants.*.options' => ['nullable', 'array'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100'],
            'variants.*.image_url' => ['nullable', 'url', 'max:500'],
            'variants.*.position' => ['nullable', 'integer', 'min:0'],

            // M2M relations
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', tenant_exists('categories')],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['integer', tenant_exists('tags')],
        ];
    }
}
