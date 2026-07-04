<?php

declare(strict_types=1);

namespace Database\Factories\Quotations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Quotation>
 *
 * Usage patterns:
 *   Quotation::factory()->forTenant($tenant)->create()
 *   Quotation::factory()->forTenant($tenant)->forCustomer($customer)->sent()->create()
 *   Quotation::factory()->forBranch($branch)->accepted()->create()
 *
 * forTenant() should always be set — every quotation belongs to a tenant
 * (the legacy schema's missing tenant_id was the bug this retrofit fixes).
 *
 * Note: factory create() does NOT hydrate DB column defaults onto the in-memory
 * model. Tests asserting default values (e.g. status='draft', tax_rate_bps=0)
 * must call ->fresh() to reload from the database.
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(5000, 200000);
        $issueDate = $this->faker->dateTimeBetween('-1 month', 'now')->format('Y-m-d');

        return [
            'branch_id' => null,
            'customer_id' => null,
            'quotation_number' => 'COT-'.date('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'issue_date' => $issueDate,
            'valid_until' => $this->faker->optional(0.7)->dateTimeBetween($issueDate, '+30 days')?->format('Y-m-d'),
            'subtotal_cents' => $subtotal,
            'discount_cents' => 0,
            'tax_rate_bps' => 0,
            'tax_cents' => 0,
            'total_cents' => $subtotal,
            'status' => 'draft',
            'notes' => $this->faker->optional(0.4)->sentence(),
            'terms' => $this->faker->optional(0.3)->sentence(),
            'converted_order_id' => null,
            'assigned_to' => null,
            'created_by' => null,
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

    public function createdBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'created_by' => $user->id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => $user->id,
        ]);
    }

    /**
     * Quotation that has been sent to the customer.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
        ]);
    }

    /**
     * Quotation that was accepted by the customer.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
        ]);
    }

    /**
     * Quotation that was rejected by the customer.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
        ]);
    }

    /**
     * Quotation that expired (valid_until passed without response).
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'valid_until' => $this->faker->dateTimeBetween('-2 months', '-1 day')->format('Y-m-d'),
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
