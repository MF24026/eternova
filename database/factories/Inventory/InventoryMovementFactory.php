<?php

declare(strict_types=1);

namespace Database\Factories\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InventoryMovement>
 *
 * Because InventoryMovement is append-only, the factory only ever creates rows — never
 * attempts to update them. Use forBranch() + forVariant() at minimum before create().
 */
class InventoryMovementFactory extends Factory
{
    protected $model = InventoryMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'product_variant_id' => null,
            'type' => InventoryMovement::TYPE_ENTRY,
            'quantity' => $this->faker->numberBetween(1, 50),
            'reference_type' => null,
            'reference_id' => null,
            'notes' => null,
            'user_id' => null,
        ];
    }

    public function entry(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryMovement::TYPE_ENTRY,
            'quantity' => abs((int) $this->faker->numberBetween(1, 100)),
        ]);
    }

    public function exit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryMovement::TYPE_EXIT,
            'quantity' => -abs((int) $this->faker->numberBetween(1, 50)),
        ]);
    }

    public function adjustment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryMovement::TYPE_ADJUSTMENT,
            'quantity' => $this->faker->numberBetween(-20, 20),
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryMovement::TYPE_TRANSFER,
            'quantity' => $this->faker->numberBetween(1, 30),
            'reference_type' => 'Transfer',
            'reference_id' => (string) Str::uuid(),
        ]);
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

    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    public function withReference(string $type, string $id): static
    {
        return $this->state(fn (array $attributes) => [
            'reference_type' => $type,
            'reference_id' => $id,
        ]);
    }
}
