<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BranchInventory>
 *
 * Usage:
 *   BranchInventory::factory()->forBranch($branch)->forVariant($variant)->create()
 *
 * Both forBranch() and forVariant() must be called — a BranchInventory row cannot
 * exist without an explicit branch and variant.
 */
class BranchInventoryFactory extends Factory
{
    protected $model = BranchInventory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // branch_id and product_variant_id must be set via state methods.
            'branch_id' => null,
            'product_variant_id' => null,
            'quantity' => $this->faker->numberBetween(10, 100),
            'reserved' => 0,
        ];
    }

    public function forBranch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $branch->tenant_id,
            'branch_id' => $branch->id,
        ]);
    }

    public function forVariant(ProductVariant $variant): static
    {
        return $this->state(fn (array $attributes) => [
            'product_variant_id' => $variant->id,
        ]);
    }

    public function withStock(int $quantity, int $reserved = 0): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
            'reserved' => $reserved,
        ]);
    }
}
