<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Models\WebhookLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebhookLog>
 */
final class WebhookLogFactory extends Factory
{
    protected $model = WebhookLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'gateway' => 'wompi',
            'event_type' => 'transaction.updated',
            'payload' => [
                'event' => 'transaction.updated',
                'data' => [
                    'transaction' => [
                        'id' => $this->faker->uuid(),
                        'status' => 'APPROVED',
                    ],
                ],
            ],
            'signature' => $this->faker->sha256(),
            'processed_at' => null,
            'error' => null,
        ];
    }

    /**
     * Wompi-origin webhook with a realistic event payload.
     */
    public function wompi(): static
    {
        return $this->state(fn (array $attributes) => [
            'gateway' => 'wompi',
            'event_type' => $this->faker->randomElement([
                'transaction.updated',
                'transaction.voided',
                'nequi_token.updated',
            ]),
        ]);
    }

    /**
     * Already-processed webhook — processed_at is set, no error.
     */
    public function processed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_at' => now()->subMinutes(5),
            'error' => null,
        ]);
    }

    /**
     * Failed webhook — processing attempted but errored.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'processed_at' => null,
            'error' => 'Subscription not found for reference: '.$this->faker->uuid(),
        ]);
    }
}
