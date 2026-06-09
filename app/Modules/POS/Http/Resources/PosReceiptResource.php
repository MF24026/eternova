<?php

declare(strict_types=1);

namespace App\Modules\POS\Http\Resources;

use App\Http\Resources\Api\V1\BaseResource;
use App\Modules\Orders\Models\Order;
use Illuminate\Http\Request;

/**
 * Receipt-shaped representation of a completed order.
 *
 * Designed for thermal/screen receipt rendering. Includes business info from
 * the tenant, branch name, cashier (the user who made the sale), customer,
 * and all line items from the immutable product_snapshot.
 *
 * The product_snapshot on each OrderItem is the source of truth for item
 * names/options — it reflects what was sold at the time of sale, regardless
 * of subsequent product edits.
 *
 * Requires the order to be loaded with: branch.tenant, customer, user, items.
 *
 * @property Order $resource
 */
final class PosReceiptResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toResourceArray(Request $request): array
    {
        /** @var Order $order */
        $order = $this->resource;

        $tenant = $order->branch?->tenant;

        return [
            'order_number' => $order->order_number,
            'created_at' => $order->created_at?->toIso8601String(),
            'status' => $order->status,
            'payment_method' => $order->payment_method,
            'payment_status' => $order->payment_status,

            'business' => [
                'name' => $tenant?->business_name ?? $tenant?->name ?? '',
                'logo_url' => $tenant?->logo_url,
                'primary_color' => $tenant?->primary_color,
            ],

            'branch' => [
                'name' => $order->branch?->name ?? '',
                'address' => $order->branch?->address,
                'phone' => $order->branch?->phone,
            ],

            'customer' => $order->customer !== null
                ? ['id' => $order->customer->id, 'name' => $order->customer->name]
                : null,

            'cashier' => [
                'id' => $order->user?->id,
                'name' => $order->user?->name,
            ],

            'items' => $order->items
                ->map(static fn ($item): array => [
                    'name' => $item->product_snapshot['name'] ?? '',
                    'variant_options' => $item->product_snapshot['variant_options'] ?? [],
                    'sku' => $item->product_snapshot['sku'] ?? null,
                    'quantity' => $item->quantity,
                    'unit_price_cents' => $item->unit_price_cents,
                    'total_cents' => $item->total_cents,
                ])
                ->values()
                ->all(),

            'subtotal_cents' => $order->subtotal_cents,
            'tax_cents' => $order->tax_cents,
            'discount_cents' => $order->discount_cents,
            'total_cents' => $order->total_cents,
        ];
    }
}
