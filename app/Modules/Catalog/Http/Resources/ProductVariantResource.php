<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;

/**
 * @extends BaseResource
 *
 * @property ProductVariant $resource
 */
final class ProductVariantResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var ProductVariant $variant */
        $variant = $this->resource;

        return [
            'id' => $variant->id,
            'product_id' => $variant->product_id,
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price_cents' => $variant->price_cents,
            'cost_price_cents' => $variant->cost_price_cents,
            'weight_grams' => $variant->weight_grams,
            'options' => $variant->options ?? [],
            'image_url' => $variant->image_url,
            'position' => $variant->position,
            'is_active' => $variant->is_active,
            'min_stock_alert' => $variant->min_stock_alert,
            // Present only when the caller eager-loaded stock (product detail); the
            // single-variant add/update responses omit it. Keyed by branch id so the
            // admin overlay can filter to the selected branch client-side.
            'available_by_branch' => $this->when(
                $variant->relationLoaded('branchInventory'),
                static fn () => $variant->branchInventory
                    ->mapWithKeys(static fn ($bi) => [(string) $bi->branch_id => (int) $bi->available]),
            ),
            'deleted_at' => $variant->deleted_at?->toIso8601String(),
            'created_at' => $variant->created_at?->toIso8601String(),
            'updated_at' => $variant->updated_at?->toIso8601String(),
        ];
    }
}
