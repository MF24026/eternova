<?php

declare(strict_types=1);

namespace Database\Factories\Customers;

use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 *
 * IMPORTANT: requires a tenant context. Use forTenant($tenant) state or resolve a
 * tenant in the container before calling create().
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // tenant_id resolved from BelongsToTenant::creating() or forTenant() state
            'name' => $this->faker->name(),
            'email' => $this->faker->optional(0.7)->safeEmail(),
            'phone' => null,
            'whatsapp' => null,
            'address' => $this->faker->optional(0.4)->address(),
            'notes' => $this->faker->optional(0.3)->sentence(),
            'total_purchases' => 0,
            'last_purchase_at' => null,
        ];
    }

    /**
     * Associate the customer with a specific tenant, bypassing the global
     * BelongsToTenant auto-set. Required when creating customers outside a
     * resolved HTTP context (tests, seeders).
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Customer with an 8-digit phone (El Salvador format by default).
     * Provide any string to test other countries.
     */
    public function withPhone(string $phone = '76543210'): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => $phone,
        ]);
    }

    /**
     * Customer with both phone and whatsapp set to the same number.
     */
    public function withWhatsapp(string $phone = '76543210'): static
    {
        return $this->state(fn (array $attributes) => [
            'phone' => $phone,
            'whatsapp' => $phone,
        ]);
    }
}
