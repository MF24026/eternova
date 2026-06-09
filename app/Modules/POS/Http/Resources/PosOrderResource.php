<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;

/**
 * Order confirmation returned immediately after a successful POS checkout.
 *
 * Includes the order header plus all line items so the terminal can render a
 * confirmation screen without a second round-trip. The receipt endpoint returns
 * the same data enriched with business info (tenant name, branch name, cashier).
 *
 * @property Order $resource
 */
final class PosOrderResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'source' => $order->source,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,
            'subtotal_cents' => $order->subtotal_cents,
            'tax_cents' => $order->tax_cents,
            'discount_cents' => $order->discount_cents,
            'total_cents' => $order->total_cents,
            'notes' => $order->notes,
            'created_at' => $order->created_at?->toIso8601String(),
            'customer' => $this->when(
                $order->relationLoaded('customer') && $order->customer !== null,
                static fn () => [
                    'id' => $order->customer?->id,
                    'name' => $order->customer?->name,
                ],
            ),
            'items' => $this->when(
                $order->relationLoaded('items'),
                static fn () => $order->items
                    ->map(static fn ($item): array => [
                        'id' => $item->id,
                        'product_variant_id' => $item->product_variant_id,
                        'quantity' => $item->quantity,
                        'unit_price_cents' => $item->unit_price_cents,
                        'total_cents' => $item->total_cents,
                        'name' => $item->product_snapshot['name'] ?? null,
                        'variant_options' => $item->product_snapshot['variant_options'] ?? [],
                        'sku' => $item->product_snapshot['sku'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ),
        ];
    }
}
