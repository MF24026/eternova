<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 *
 * IMPORTANT: requires a tenant context. Use forTenant($tenant) state or resolve a
 * tenant in the container before calling create().
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = $this->faker->randomElement([
            'Arreglos florales',
            'Bouquets',
            'Rosas eternas',
            'Centros de mesa',
            'Globos',
            'Peluches',
            'Regalos',
            'Joyeria',
            'Decoracion hogar',
            'Detalles corporativos',
            'Ocasiones especiales',
            'Cumpleanos',
            'Aniversarios',
            'Bodas',
        ]);

        $slug = Str::slug($name).'-'.strtolower(bin2hex(random_bytes(4)));

        return [
            // tenant_id resolved from BelongsToTenant::creating() or forTenant() state
            'name' => $name,
            'slug' => $slug,
            'description' => $this->faker->optional(0.6)->sentence(),
            'image_url' => null,
            'parent_id' => null,
            'sort_order' => $this->faker->numberBetween(0, 20),
            'is_active' => true,
        ];
    }

    /**
     * Associate with a specific tenant, bypassing the global BelongsToTenant auto-set.
     * Required when creating categories outside a resolved HTTP context.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Assign a parent category to make this a subcategory.
     * The parent must belong to the same tenant.
     */
    public function withParent(Category $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
        ]);
    }

    /**
     * Mark the category as inactive (hidden from storefront).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Pin category near the top of any ordered listing.
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'sort_order' => 0,
            'is_active' => true,
        ]);
    }
}
