<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * POS product representation for the checkout grid.
 *
 * Includes all fields relevant to an internal terminal: cost price, sku_root,
 * and per-branch exact stock quantities. These are intentionally absent from the
 * public StorefrontProductResource which hides operational data from shoppers.
 *
 * The stock map (Collection<int, int> keyed by product_variant_id → available qty)
 * is injected via additional(['stock' => $map]) at the collection level, then
 * threaded down to each PosVariantResource.
 *
 * @property Product $resource
 */
final class PosProductResource extends BaseResource
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
            'sku_root' => $product->sku_root,
            'base_price_cents' => $product->base_price_cents,
            'default_image_url' => $product->default_image_url,
            'is_active' => $product->is_active,
            'categories' => $this->when(
                $product->relationLoaded('categories'),
                static fn () => $product->categories
                    ->map(static fn ($cat): array => [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                    ])
                    ->values()
                    ->all(),
            ),
            'variants' => $this->when(
                $product->relationLoaded('variants'),
                function () use ($product): array {
                    /** @var Collection<int, int>|null $stock */
                    $stock = $this->additional['stock'] ?? null;

                    return $product->variants
                        ->map(static fn ($variant) => (new PosVariantResource($variant))
                            ->additional($stock !== null ? ['stock' => $stock] : [])
                            ->toArray(request())
                        )
                        ->values()
                        ->all();
                },
            ),
        ];
    }
}
