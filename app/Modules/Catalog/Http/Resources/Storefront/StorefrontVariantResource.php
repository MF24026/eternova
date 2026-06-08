<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources\Storefront;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Public variant representation for the storefront.
 *
 * Stock availability design decision:
 *   - We expose `in_stock` (boolean) and `low_stock` (boolean, available <= 3)
 *     rather than the exact available quantity.
 *   - Rationale: exact counts would let competitors gauge supply and enable
 *     inventory-sniping. A boolean + low-stock signal is enough for the shopper
 *     ("buy now before it runs out") without leaking operational data.
 *   - Stock data is injected via `additional(['stock' => $keyedCollection])` on the
 *     collection rather than via a relation on ProductVariant (which we cannot modify).
 *     When no stock data is present, the variant defaults to out-of-stock (safe default).
 *
 * NEVER exposed: cost_price_cents, min_stock_alert, weight_grams, barcode,
 * product_id, position (internal sort), deleted_at, timestamps.
 *
 * @property ProductVariant $resource
 */
final class StorefrontVariantResource extends BaseResource
{
    /**
     * Quantity at or below which a variant is considered "low stock".
     * Chosen conservatively: 3 units triggers the low-stock badge.
     */
    private const LOW_STOCK_THRESHOLD = 3;

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
            // null means "inherit base_price_cents from the parent product"
            'price_cents' => $variant->price_cents,
            'options' => $variant->options ?? [],
            'image_url' => $variant->image_url,
            'in_stock' => $availableQty > 0,
            'low_stock' => $availableQty > 0 && $availableQty <= self::LOW_STOCK_THRESHOLD,
        ];
    }

    /**
     * Read available quantity from the stock map injected via additional().
     *
     * Expected: `additional(['stock' => Collection<int, int>])` where keys are
     * product_variant_id and values are the available quantity from branch_inventory.
     *
     * When the stock map is absent (e.g. in admin contexts), defaults to 0 — the
     * storefront always has stock injected, so 0 is the correct safe default.
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
