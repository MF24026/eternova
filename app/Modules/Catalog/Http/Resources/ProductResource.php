<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @property Product $resource
 */
final class ProductResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;

        return [
            'id' => $product->id,
            'name' => $product->name,
            'slug' => $product->slug,
            'description' => $product->description,
            'sku_root' => $product->sku_root,
            'base_price_cents' => $product->base_price_cents,
            'cost_price_cents' => $product->cost_price_cents,
            'default_image_url' => $product->default_image_url,
            'gallery' => $product->gallery ?? [],
            'is_active' => $product->is_active,
            'is_featured' => $product->is_featured,
            'tax_rate' => $product->tax_rate,
            'variants' => $this->when(
                $product->relationLoaded('variants'),
                static fn () => ProductVariantResource::collection($product->variants),
            ),
            'options' => $this->when(
                $product->relationLoaded('options'),
                static fn () => $product->options->map(
                    static fn (ProductOption $option): array => [
                        'id' => $option->id,
                        'name' => $option->name,
                        'position' => $option->position,
                        'values' => $option->relationLoaded('values')
                            ? $option->values->map(static fn ($v): array => [
                                'id' => $v->id,
                                'value' => $v->value,
                                'position' => $v->position,
                            ])->values()->all()
                            : [],
                    ]
                )->values()->all(),
            ),
            'categories' => $this->when(
                $product->relationLoaded('categories'),
                static fn () => CategoryResource::collection($product->categories),
            ),
            'tags' => $this->when(
                $product->relationLoaded('tags'),
                static fn () => TagResource::collection($product->tags),
            ),
            'deleted_at' => $product->deleted_at?->toIso8601String(),
            'created_at' => $product->created_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];
    }
}
