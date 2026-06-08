<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use Illuminate\Support\Str;

/**
 * Handles variant-level operations and matrix generation.
 *
 * Matrix generation: given 2 options [Color: [Rojo, Verde], Tamano: [S, M]]
 * the cartesian product produces 4 variants:
 *   {Color:Rojo, Tamano:S}, {Color:Rojo, Tamano:M},
 *   {Color:Verde, Tamano:S}, {Color:Verde, Tamano:M}
 * each with a default SKU of "{sku_root}-rojo-s", "{sku_root}-rojo-m", etc.
 */
final class ProductVariantService
{
    /**
     * Generate and persist the cartesian product of option values as variants.
     *
     * @param  array<int, array{name: string, values: list<string>}>  $options
     *                                                                          Option definitions with their value lists.
     * @param  int  $basePriceCents  Inherited from product when variant has no override.
     */
    public function generateMatrix(
        Product $product,
        array $options,
        int $basePriceCents,
    ): void {
        if ($options === []) {
            return;
        }

        $combinations = $this->cartesianProduct($options);
        $skuRoot = $product->sku_root ?? Str::slug($product->name);

        foreach ($combinations as $position => $combo) {
            $slugParts = array_map(
                static fn (string $val): string => Str::slug($val),
                array_values($combo),
            );

            $sku = $skuRoot.'-'.implode('-', $slugParts);
            // Truncate to ensure we stay within the 191-char unique index limit.
            $sku = substr($sku, 0, 191);

            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $sku,
                'price_cents' => null, // falls back to product.base_price_cents
                'options' => $combo,
                'position' => $position,
            ]);
        }
    }

    /**
     * Persist an explicit list of variant definitions supplied by the caller.
     *
     * When the caller provides variants directly they bypass matrix generation —
     * this lets the frontend send customised SKUs and per-variant prices.
     *
     * @param  array<int, array{sku?: string, price_cents?: int|null, options?: array<string, string>, barcode?: string|null, image_url?: string|null, position?: int}>  $variants
     */
    public function createExplicitVariants(Product $product, array $variants): void
    {
        foreach ($variants as $position => $data) {
            $skuRoot = $product->sku_root ?? Str::slug($product->name);
            $sku = $data['sku'] ?? ($skuRoot.'-'.($position + 1));
            $sku = substr($sku, 0, 191);

            ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $sku,
                'barcode' => $data['barcode'] ?? null,
                'price_cents' => $data['price_cents'] ?? null,
                'options' => $data['options'] ?? [],
                'image_url' => $data['image_url'] ?? null,
                'position' => $data['position'] ?? $position,
            ]);
        }
    }

    /**
     * Add a single variant to an existing product.
     *
     * @param  array<string, mixed>  $data
     */
    public function addVariant(Product $product, array $data): ProductVariant
    {
        $skuRoot = $product->sku_root ?? Str::slug($product->name);
        $sku = $data['sku'] ?? ($skuRoot.'-'.($product->variants()->withTrashed()->count() + 1));
        $sku = substr($sku, 0, 191);

        return ProductVariant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'barcode' => $data['barcode'] ?? null,
            'price_cents' => $data['price_cents'] ?? null,
            'cost_price_cents' => $data['cost_price_cents'] ?? null,
            'weight_grams' => $data['weight_grams'] ?? null,
            'options' => $data['options'] ?? [],
            'image_url' => $data['image_url'] ?? null,
            'position' => $data['position'] ?? 0,
        ]);
    }

    /**
     * Update a single variant.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateVariant(ProductVariant $variant, array $data): ProductVariant
    {
        $variant->update($data);

        return $variant->fresh() ?? $variant;
    }

    /**
     * Reorder variants by updating their position column.
     *
     * @param  array<int, array{id: int, position: int}>  $items
     */
    public function reorder(array $items): void
    {
        foreach ($items as $item) {
            ProductVariant::where('id', $item['id'])
                ->update(['position' => $item['position']]);
        }
    }

    /**
     * Compute the cartesian product of option values.
     *
     * Input:  [{name: "Color", values: ["Rojo", "Verde"]}, {name: "Tamano", values: ["S", "M"]}]
     * Output: [
     *   {Color: "Rojo", Tamano: "S"},
     *   {Color: "Rojo", Tamano: "M"},
     *   {Color: "Verde", Tamano: "S"},
     *   {Color: "Verde", Tamano: "M"},
     * ]
     *
     * @param  array<int, array{name: string, values: list<string>}>  $options
     * @return list<array<string, string>>
     */
    private function cartesianProduct(array $options): array
    {
        $result = [[]];

        foreach ($options as $option) {
            $append = [];

            foreach ($result as $existing) {
                foreach ($option['values'] as $value) {
                    $append[] = array_merge($existing, [$option['name'] => $value]);
                }
            }

            $result = $append;
        }

        /** @var list<array<string, string>> $result */
        return $result;
    }
}
