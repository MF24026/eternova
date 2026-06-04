<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 *
 * IMPORTANT: BranchFactory requires a tenant to be set before creation.
 * Either:
 *   - Use the forTenant($tenant) state: Branch::factory()->forTenant($tenant)->create()
 *   - Resolve a tenant in the container first: app()->instance('currentTenant', $tenant)
 *
 * The factory does NOT silently fall back to a random tenant to avoid cross-tenant pollution.
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->randomElement([
            'Sucursal Centro',
            'Sucursal Norte',
            'Sucursal Sur',
            'Sucursal Mall',
            'Sucursal Principal',
            'Tienda Central',
        ]);

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'sucursal');
        $slug = trim($slug, '-').'-'.$this->faker->unique()->numberBetween(1000, 9999);

        return [
            // tenant_id is intentionally omitted here — resolved from BelongsToTenant::creating()
            // or explicitly set via the forTenant() state.
            'name' => $name,
            'slug' => $slug,
            'address' => $this->faker->address(),
            'phone' => $this->faker->phoneNumber(),
            'is_main' => false,
            'is_active' => true,
        ];
    }

    /**
     * Associate this branch with a specific tenant.
     *
     * Use this state in tests to set up explicit cross-tenant scenarios, or whenever
     * you're creating branches outside of a resolved tenant HTTP context.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Mark this branch as the main (flagship) branch.
     */
    public function main(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_main' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Mark this branch as inactive (closed / suspended).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
