<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\Storefront;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public product representation for the storefront.
 *
 * Exposes only: id, name, slug, description, base_price_cents, default_image_url,
 * gallery, is_featured, tax_rate, categories (slug+name), tags (slug+name),
 * variants (StorefrontVariantResource with stock availability).
 *
 * NEVER exposed: cost_price_cents, sku_root, tenant_id, deleted_at,
 * created_at/updated_at (internal timestamps), is_active (implied: only active
 * products are served), admin-only relations (options with internal ids).
 *
 * The `stock` map (Collection<int, int> keyed by product_variant_id → available qty)
 * must be injected via `additional(['stock' => $map])` when serving the detail
 * endpoint. On the list endpoint, variants are NOT included (too expensive).
 *
 * @property Product $resource
 */
final class StorefrontProductResource extends BaseResource
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
            'base_price_cents' => $product->base_price_cents,
            'default_image_url' => $product->default_image_url,
            'gallery' => $product->gallery ?? [],
            'is_featured' => $product->is_featured,
            'tax_rate' => $product->tax_rate,
            'categories' => $this->when(
                $product->relationLoaded('categories'),
                static fn () => $product->categories->map(
                    static fn ($cat): array => [
                        'name' => $cat->name,
                        'slug' => $cat->slug,
                    ]
                )->values()->all(),
            ),
            'tags' => $this->when(
                $product->relationLoaded('tags'),
                static fn () => $product->tags->map(
                    static fn ($tag): array => [
                        'name' => $tag->name,
                        'slug' => $tag->slug,
                    ]
                )->values()->all(),
            ),
            'variants' => $this->when(
                $product->relationLoaded('variants'),
                function () use ($product): array {
                    /** @var Collection<int, int>|null $stock */
                    $stock = $this->additional['stock'] ?? null;

                    return $product->variants
                        ->map(static fn ($variant) => (new StorefrontVariantResource($variant))
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
