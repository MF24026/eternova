<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductOption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductOption>
 *
 * IMPORTANT: always call forProduct($product) — an option cannot exist without a product.
 */
class ProductOptionFactory extends Factory
{
    protected $model = ProductOption::class;

    /**
     * @var list<string>
     */
    private const OPTION_NAMES = [
        'Color',
        'Tamano',
        'Material',
        'Estilo',
        'Aroma',
        'Tipo de flor',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // product_id must be set via forProduct() state
            'product_id' => null,
            'name' => $this->faker->randomElement(self::OPTION_NAMES),
            'position' => $this->faker->numberBetween(0, 10),
        ];
    }

    /**
     * Bind this option to a specific product.
     */
    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
        ]);
    }
}
