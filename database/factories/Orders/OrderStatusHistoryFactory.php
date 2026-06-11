<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 *
 * Usage:
 *   OrderStatusHistory::factory()->forOrder($order)->to('ready')->create()
 *   OrderStatusHistory::factory()->initial()->forOrder($order)->create()
 */
class OrderStatusHistoryFactory extends Factory
{
    protected $model = OrderStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statuses = ['pending', 'preparing', 'ready', 'dispatched', 'delivered', 'cancelled'];

        return [
            'order_id' => null,
            'from_status' => $this->faker->randomElement($statuses),
            'to_status' => $this->faker->randomElement($statuses),
            'user_id' => null,
            'note' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $order->tenant_id,
            'order_id' => $order->id,
        ]);
    }

    /**
     * Represents the creation entry (no prior status).
     */
    public function initial(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_status' => null,
            'to_status' => 'preparing',
        ]);
    }

    /**
     * Set a specific target status.
     */
    public function to(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'to_status' => $status,
        ]);
    }

    /**
     * Set a specific source status.
     */
    public function from(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'from_status' => $status,
        ]);
    }
}
