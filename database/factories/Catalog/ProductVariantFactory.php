<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 *
 * IMPORTANT: always call forProduct($product) — a variant cannot exist without a product.
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // product_id must be set via forProduct() state
            'product_id' => null,
            'sku' => 'SKU-'.strtoupper(bin2hex(random_bytes(5))),
            'barcode' => $this->faker->optional(0.5)->ean13(),
            'price_cents' => null,
            'cost_price_cents' => null,
            'weight_grams' => $this->faker->optional(0.4)->numberBetween(50, 5000),
            'options' => [],
            'image_url' => null,
            'position' => 0,
        ];
    }

    /**
     * Bind this variant to a product and derive its SKU from sku_root if available.
     *
     * SKU suffix uses a random hex segment so that count(N) calls never collide —
     * faker->unique() resets per factory instantiation and is not safe for bulk creates.
     */
    public function forProduct(Product $product): static
    {
        $skuRoot = $product->sku_root ?? 'VAR';

        return $this->state(fn (array $attributes) => [
            'product_id' => $product->id,
            'sku' => $skuRoot.'-'.strtoupper(bin2hex(random_bytes(4))),
        ]);
    }

    /**
     * Add option key-value pairs to the variant's JSON options column.
     *
     * Example: withOptions(['Color' => 'Rojo', 'Tamano' => 'Grande'])
     *
     * @param  array<string, string>  $options
     */
    public function withOptions(array $options): static
    {
        return $this->state(fn (array $attributes) => [
            'options' => $options,
        ]);
    }

    /**
     * Override the variant price (otherwise the product's base_price_cents is used).
     */
    public function withPrice(int $priceCents): static
    {
        return $this->state(fn (array $attributes) => [
            'price_cents' => $priceCents,
        ]);
    }
}
