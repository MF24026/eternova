<?php

declare(strict_types=1);

namespace Database\Factories\Reservations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 *
 * Usage patterns:
 *   Reservation::factory()->forTenant($tenant)->create()
 *   Reservation::factory()->forTenant($tenant)->forCustomer($customer)->confirmed()->create()
 *
 * forTenant() should always be set — every reservation belongs to a tenant
 * (the legacy schema's missing tenant_id was the bug this retrofit fixes).
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $total = $this->faker->numberBetween(5000, 80000);
        $depositRequired = (int) round($total * 0.30);

        return [
            'branch_id' => null,
            'customer_id' => null,
            'reservation_number' => 'RSV-'.date('Y').'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'description' => $this->faker->sentence(),
            'occasion' => $this->faker->randomElement(['Boda', 'Cumpleanos', 'Corporativo', 'Quinceanera', 'Aniversario']),
            'event_date' => $this->faker->dateTimeBetween('+3 days', '+2 months')->format('Y-m-d'),
            'total_cents' => $total,
            'deposit_required_cents' => $depositRequired,
            'deposit_paid_cents' => 0,
            'status' => 'inquiry',
            'special_instructions' => $this->faker->optional(0.4)->sentence(),
            'admin_notes' => $this->faker->optional(0.2)->sentence(),
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

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => $user->id,
        ]);
    }

    /**
     * A confirmed reservation with its deposit fully paid.
     */
    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'confirmed',
            'deposit_paid_cents' => $attributes['deposit_required_cents'] ?? 0,
        ]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => $status,
        ]);
    }
}
