<?php

declare(strict_types=1);

namespace Database\Factories\Quotations;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuotationItem>
 *
 * Usage patterns:
 *   QuotationItem::factory()->forQuotation($quotation)->create()
 *   QuotationItem::factory()->forQuotation($quotation)->count(3)->create()
 *
 * forQuotation() should always be set — items need both tenant_id and quotation_id
 * and the factory derives tenant_id from the parent quotation.
 *
 * Note: factory create() does NOT hydrate DB column defaults onto the in-memory
 * model. Tests asserting sort_order=0 must call ->fresh() to reload from the database.
 */
class QuotationItemFactory extends Factory
{
    protected $model = QuotationItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = $this->faker->numberBetween(1, 10);
        $unitPrice = $this->faker->numberBetween(500, 20000);

        return [
            'quotation_id'      => null,
            'product_id'        => null,
            'description'       => $this->faker->words(3, true),
            'quantity'          => $qty,
            'unit_price_cents'  => $unitPrice,
            'line_total_cents'  => $qty * $unitPrice,
            'sort_order'        => 0,
        ];
    }

    public function forQuotation(Quotation $quotation): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id'    => $quotation->tenant_id,
            'quotation_id' => $quotation->id,
        ]);
    }

    /**
     * Set the display order of this line item within the quotation.
     */
    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'sort_order' => $position,
        ]);
    }
}
