<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the multipart image upload for a product.
 *
 * Authorization is double-layered:
 *   1. FormRequest::authorize() — fast-path check before validation even runs.
 *   2. Controller calls $this->authorize('update', $product) — explicit gate check.
 *
 * This mirrors the pattern from #32 where double-layer was mandated after a
 * regression where FormRequest alone was bypassed by an edge-case route binding.
 */
final class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Product|null $product */
        $product = $this->route('product');

        if ($product === null) {
            return false;
        }

        return $this->user()?->can('update', $product) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxKilobytes = (int) (config('catalog.image_max_bytes') / 1024);

        return [
            'image' => [
                'required',
                'file',
                "max:{$maxKilobytes}",
                'mimes:jpg,jpeg,png,webp',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = round(config('catalog.image_max_bytes') / 1024 / 1024, 1);

        return [
            'image.required' => 'An image file is required.',
            'image.file' => 'The upload must be a file.',
            'image.max' => "The image may not be larger than {$maxMb} MB.",
            'image.mimes' => 'The image must be a JPEG, PNG, or WebP file.',
        ];
    }
}
