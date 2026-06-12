<?php

declare(strict_types=1);

namespace Database\Factories\Reservations;

use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservationStatusHistory>
 *
 * Usage:
 *   ReservationStatusHistory::factory()->forReservation($reservation)->to('confirmed')->create()
 *   ReservationStatusHistory::factory()->initial()->forReservation($reservation)->create()
 */
class ReservationStatusHistoryFactory extends Factory
{
    protected $model = ReservationStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statuses = ['inquiry', 'confirmed', 'in_progress', 'ready', 'delivered', 'cancelled'];

        return [
            'reservation_id' => null,
            'from_status' => $this->faker->randomElement($statuses),
            'to_status' => $this->faker->randomElement($statuses),
            'user_id' => null,
            'note' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    public function forReservation(Reservation $reservation): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $reservation->tenant_id,
            'reservation_id' => $reservation->id,
        ]);
    }

    /**
     * Represents the initial creation entry (no prior status).
     */
    public function initial(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_status' => null,
            'to_status' => 'inquiry',
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
