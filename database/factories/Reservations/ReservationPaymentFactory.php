<?php

declare(strict_types=1);

namespace Database\Factories\Reservations;

use App\Models\User;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationPayment;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationPayment>
 *
 * Usage:
 *   ReservationPayment::factory()->forReservation($reservation)->create()
 *
 * forReservation() sets both reservation_id and tenant_id from the parent so the
 * payment is always tenant-scoped consistently with its reservation.
 */
class ReservationPaymentFactory extends Factory
{
    protected $model = ReservationPayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'amount_cents' => $this->faker->numberBetween(1000, 30000),
            'payment_method' => $this->faker->randomElement(['cash', 'card', 'transfer', 'other']),
            'reference' => $this->faker->optional(0.3)->bothify('REF-####'),
            'recorded_by' => null,
            'paid_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    public function forReservation(Reservation $reservation): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $reservation->tenant_id,
            'reservation_id' => $reservation->id,
        ]);
    }

    public function recordedBy(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'recorded_by' => $user->id,
        ]);
    }
}
