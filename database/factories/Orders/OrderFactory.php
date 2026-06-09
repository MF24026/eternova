<?php

declare(strict_types=1);

namespace Database\Factories\Orders;

use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 *
 * Usage patterns:
 *   Order::factory()->forTenant($tenant)->forBranch($branch)->create()
 *   Order::factory()->paid()->forBranch($branch)->create()
 *   Order::factory()->forCustomer($customer)->forBranch($branch)->create()
 *
 * Both forTenant() AND forBranch() should be set — branch implies a tenant but
 * explicit states produce clearer test intentions.
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(500, 20000);

        return [
            'branch_id' => null,
            'customer_id' => null,
            'order_number' => 'CC-'.date('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => 'preparing',
            'source' => 'pos',
            'subtotal_cents' => $subtotal,
            'tax_cents' => 0,
            'discount_cents' => 0,
            'total_cents' => $subtotal,
            'payment_method' => $this->faker->randomElement(['cash', 'card', 'transfer']),
            'payment_status' => 'pending',
            'notes' => $this->faker->optional(0.3)->sentence(),
            'user_id' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (array $attributes) => [
            'customer_id' => $customer->id,
        ]);
    }

    /**
     * Mark the order as fully paid (POS checkout state).
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'paid',
            'status' => 'preparing',
        ]);
    }

    /**
     * Mark the order as pending payment (catalog / reservation state).
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'payment_status' => 'pending',
            'status' => 'pending',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
        ]);
    }
}
