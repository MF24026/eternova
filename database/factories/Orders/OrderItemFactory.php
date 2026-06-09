<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 *
 * Usage:
 *   OrderItem::factory()->forOrder($order)->forVariant($variant)->create()
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unitPrice = $this->faker->numberBetween(100, 5000);
        $quantity = $this->faker->numberBetween(1, 5);

        return [
            'order_id' => null,
            'product_variant_id' => null,
            'quantity' => $quantity,
            'unit_price_cents' => $unitPrice,
            'total_cents' => $unitPrice * $quantity,
            'product_snapshot' => [
                'name' => $this->faker->words(3, true),
                'variant_options' => [],
                'sku' => 'SKU-'.strtoupper(bin2hex(random_bytes(4))),
            ],
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'order_id' => $order->id,
        ]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        $unitPrice = $variant->price_cents ?? 1000;

        return $this->state(fn (array $attributes) => [
            'product_variant_id' => $variant->id,
            'unit_price_cents' => $unitPrice,
            'total_cents' => $unitPrice * ($attributes['quantity'] ?? 1),
            'product_snapshot' => [
                'name' => $variant->product?->name ?? 'Unknown product',
                'variant_options' => is_array($variant->options) ? $variant->options : [],
                'sku' => $variant->sku,
            ],
        ]);
    }
}
