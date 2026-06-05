<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'amount_cents' => $this->faker->randomElement([900, 2900, 9900]),
            'currency' => 'USD',
            'method' => 'card',
            'status' => 'pending',
            'gateway' => 'wompi',
            'gateway_reference' => null,
            'paid_at' => null,
            'raw_response' => null,
        ];
    }

    /**
     * Succeeded payment — gateway confirmed charge, paid_at is set.
     */
    public function succeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'succeeded',
            'gateway_reference' => 'wompi-'.$this->faker->uuid(),
            'paid_at' => now(),
            'raw_response' => [
                'id' => $this->faker->uuid(),
                'status' => 'APPROVED',
                'payment_method_type' => 'CARD',
            ],
        ]);
    }

    /**
     * Failed payment — gateway declined the charge.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'gateway_reference' => 'wompi-'.$this->faker->uuid(),
            'paid_at' => null,
            'raw_response' => [
                'id' => $this->faker->uuid(),
                'status' => 'DECLINED',
                'error' => ['reason' => 'CARD_DECLINED'],
            ],
        ]);
    }

    /**
     * Pending payment — charge dispatched but not yet confirmed by gateway.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'gateway_reference' => null,
            'paid_at' => null,
            'raw_response' => null,
        ]);
    }
}
