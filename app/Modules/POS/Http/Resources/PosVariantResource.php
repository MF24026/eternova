<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * POS variant representation — exposes exact available_quantity.
 *
 * Stock design decision: the POS terminal is an authenticated internal tool.
 * Staff must know exact quantities to manage the sale floor and avoid
 * over-selling. This is intentionally different from StorefrontVariantResource
 * which hides counts behind in_stock/low_stock booleans.
 *
 * Stock data is injected via additional(['stock' => Collection<int, int>])
 * where keys are product_variant_id and values are available qty.
 *
 * @property ProductVariant $resource
 */
final class PosVariantResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var ProductVariant $variant */
        $variant = $this->resource;

        $availableQty = $this->resolveAvailableQuantity($variant);

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'price_cents' => $variant->price_cents,
            'options' => $variant->options ?? [],
            'image_url' => $variant->image_url,
            // POS exposes the exact quantity (unlike the public storefront)
            'available_quantity' => $availableQty,
            'in_stock' => $availableQty > 0,
        ];
    }

    /**
     * Read available quantity from the stock map injected via additional().
     *
     * Returns 0 when the stock map is absent — the safe default is out-of-stock
     * rather than incorrectly implying unlimited supply.
     */
    private function resolveAvailableQuantity(ProductVariant $variant): int
    {
        /** @var Collection<int, int>|null $stock */
        $stock = $this->additional['stock'] ?? null;

        if ($stock === null) {
            return 0;
        }

        return (int) ($stock->get($variant->id) ?? 0);
    }
}
