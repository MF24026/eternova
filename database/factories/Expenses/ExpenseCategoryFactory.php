<?php

declare(strict_types=1);

namespace Database\Factories\Expenses;

use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 *
 * Usage patterns:
 *   ExpenseCategory::factory()->forTenant($tenant)->create()
 *   ExpenseCategory::factory()->forTenant($tenant)->type('payroll')->create()
 *
 * forTenant() should always be set — every category belongs to a tenant
 * (the legacy schema's missing tenant_id was the bug this retrofit fixes).
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name'      => $this->faker->unique()->words(2, true),
            'type'      => $this->faker->randomElement(['operating', 'products', 'payroll', 'rent', 'other']),
            'is_active' => true,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Pin this category to a specific expense type bucket.
     */
    public function type(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }

    /**
     * A retired category that is no longer shown in category pickers.
     *
     * Historical expense rows remain linked — the FK is not removed.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
