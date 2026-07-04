<?php

declare(strict_types=1);

namespace Database\Factories\Quotations;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationStatusHistory>
 *
 * Usage:
 *   QuotationStatusHistory::factory()->forQuotation($quotation)->to('sent')->create()
 *   QuotationStatusHistory::factory()->initial()->forQuotation($quotation)->create()
 */
class QuotationStatusHistoryFactory extends Factory
{
    protected $model = QuotationStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $statuses = ['draft', 'sent', 'accepted', 'rejected', 'expired'];

        return [
            'quotation_id' => null,
            'from_status' => $this->faker->randomElement($statuses),
            'to_status' => $this->faker->randomElement($statuses),
            'user_id' => null,
            'note' => $this->faker->optional(0.3)->sentence(),
        ];
    }

    public function forQuotation(Quotation $quotation): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $quotation->tenant_id,
            'quotation_id' => $quotation->id,
        ]);
    }

    /**
     * Represents the initial creation entry (no prior status).
     */
    public function initial(): static
    {
        return $this->state(fn (array $attributes) => [
            'from_status' => null,
            'to_status' => 'draft',
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
