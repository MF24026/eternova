<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Support\SlugValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateProductRequest extends FormRequest
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
        $tenantId = current_tenant()?->id;
        $productId = $this->route('product') instanceof Product
            ? $this->route('product')->id
            : null;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes',
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
                    ->whereNull('deleted_at')
                    ->ignore($productId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'sku_root' => ['sometimes', 'nullable', 'string', 'max:100'],
            'base_price_cents' => ['sometimes', 'integer', 'min:0'],
            'cost_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'default_image_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'gallery' => ['sometimes', 'nullable', 'array'],
            'gallery.*' => ['string', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'tax_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],

            // M2M sync — optional on update
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', 'exists:categories,id'],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['integer', 'exists:tags,id'],
        ];
    }
}
