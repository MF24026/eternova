<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 *
 * IMPORTANT: requires a tenant context. Use forTenant($tenant) state or resolve a
 * tenant in the container before calling create().
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Realistic florist / gift-shop product names for demo and test data.
     *
     * @var list<string>
     */
    private const PRODUCT_NAMES = [
        'Rosa Eterna Carmesi',
        'Bouquet Aurora',
        'Llavero Camelia',
        'Arreglo Primavera',
        'Peluche Osito Corazon',
        'Caja de Chocolates Artesanal',
        'Ramo de Girasoles',
        'Bouquet Novia Blanco',
        'Centro de Mesa Romantico',
        'Globo Metalico Cumpleanos',
        'Cofre de Rosas Preservadas',
        'Pulsera Floral Plata',
        'Kit Spa Lavanda',
        'Vela Aromatica Rosas',
        'Porta-retrato Floral',
        'Canasta Regalo Deluxe',
        'Orquidea Morada',
        'Tulipanes Multicolor',
        'Bouquet Tropical Hibisco',
        'Arreglo Bebe Recien Nacido',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = $this->faker->randomElement(self::PRODUCT_NAMES);
        $skuRoot = strtoupper(substr(Str::slug($name), 0, 8)).'-'.strtoupper($this->faker->lexify('??'));

        return [
            // tenant_id resolved from BelongsToTenant::creating() or forTenant() state
            'name' => $name,
            'slug' => Str::slug($name).'-'.strtolower(bin2hex(random_bytes(4))),
            'description' => $this->faker->optional(0.7)->paragraph(),
            'sku_root' => $skuRoot,
            'base_price_cents' => $this->faker->numberBetween(500, 15000),
            'cost_price_cents' => $this->faker->optional(0.8)->numberBetween(200, 8000),
            'default_image_url' => null,
            'gallery' => null,
            'is_active' => true,
            'is_featured' => false,
            'tax_rate' => null,
        ];
    }

    /**
     * Associate with a specific tenant.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Mark the product as featured (shown in highlighted sections of the storefront).
     */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_featured' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Mark the product as inactive (hidden from storefront and POS).
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
