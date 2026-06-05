<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->randomElement([900, 2900, 9900]);
        $tax = 0;
        $seq = $this->faker->unique()->numberBetween(1, 99999);

        return [
            'tenant_id' => Tenant::factory(),
            'subscription_id' => Subscription::factory(),
            'number' => sprintf('INV-%d-%05d', now()->year, $seq),
            'status' => 'open',
            'subtotal_cents' => $subtotal,
            'tax_cents' => $tax,
            'total_cents' => $subtotal + $tax,
            'currency' => 'USD',
            'due_at' => now()->addDays(7),
            'paid_at' => null,
            'wompi_transaction_id' => null,
            'pdf_url' => null,
        ];
    }

    /**
     * Paid invoice — paid_at is set, status = paid.
     */
    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paid',
            'paid_at' => now()->subHour(),
            'due_at' => now()->addDays(7),
        ]);
    }

    /**
     * Open invoice — issued and awaiting payment.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'paid_at' => null,
            'due_at' => now()->addDays(7),
        ]);
    }

    /**
     * Overdue invoice — due date has passed, still unpaid.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'paid_at' => null,
            'due_at' => now()->subDays(5),
        ]);
    }

    /**
     * Scope to a specific subscription (and inherit its tenant_id).
     */
    public function forSubscription(Subscription $subscription): static
    {
        return $this->state(fn (array $attributes) => [
            'subscription_id' => $subscription->id,
            'tenant_id' => $subscription->tenant_id,
        ]);
    }
}
