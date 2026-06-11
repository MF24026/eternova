<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Http\Request;

/**
 * Transforms a single OrderItem for API output.
 *
 * product_snapshot is the receipt source of truth — it captures name,
 * variant_options, and sku at sale time and is immutable from that point on.
 *
 * Monetary values (unit_price_cents, total_cents) follow the project convention
 * of exposing raw centavo integers. Formatting for display is the frontend's
 * responsibility.
 *
 * @property OrderItem $resource
 */
final class OrderItemResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var OrderItem $item */
        $item = $this->resource;

        /** @var array{name?: string, variant_options?: array<string, string>, sku?: string|null} $snapshot */
        $snapshot = is_array($item->product_snapshot) ? $item->product_snapshot : [];

        return [
            'id' => $item->id,
            'product_variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
            'unit_price_cents' => $item->unit_price_cents,
            'total_cents' => $item->total_cents,
            'product_snapshot' => [
                'name' => $snapshot['name'] ?? null,
                'variant_options' => $snapshot['variant_options'] ?? [],
                'sku' => $snapshot['sku'] ?? null,
            ],
        ];
    }
}
