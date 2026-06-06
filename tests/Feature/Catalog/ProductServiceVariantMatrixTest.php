<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\ProductVariantService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductServiceVariantMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_cartesian_product_of_two_options_with_two_values(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'matrix-test-tenant']);
        $product = Product::factory()->forTenant($tenant)->create([
            'slug' => 'matrix-test-product',
            'base_price_cents' => 5000,
            'sku_root' => 'TEST',
        ]);

        $service = app(ProductVariantService::class);
        $service->generateMatrix(
            $product,
            [
                ['name' => 'Color', 'values' => ['Rojo', 'Verde']],
                ['name' => 'Tamano', 'values' => ['S', 'M']],
            ],
            5000,
        );

        $variants = $product->variants()->orderBy('position')->get();

        $this->assertCount(4, $variants);

        $skus = $variants->pluck('sku')->sort()->values()->all();
        $this->assertEqualsCanonicalizing(
            ['TEST-rojo-s', 'TEST-rojo-m', 'TEST-verde-s', 'TEST-verde-m'],
            $skus,
        );
    }

    public function test_each_variant_inherits_base_price_when_no_override(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'matrix-price-tenant']);
        $product = Product::factory()->forTenant($tenant)->create([
            'slug' => 'matrix-price-product',
            'base_price_cents' => 7500,
            'sku_root' => 'PRICE-TEST',
        ]);

        $service = app(ProductVariantService::class);
        $service->generateMatrix(
            $product,
            [
                ['name' => 'Color', 'values' => ['Rojo', 'Azul']],
            ],
            7500,
        );

        // price_cents is null on each variant — they fall back to product.base_price_cents
        $product->variants()->each(static function ($v): void {
            self::assertNull($v->price_cents);
        });
    }

    public function test_generates_correct_option_json_on_each_variant(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'matrix-opts-tenant']);
        $product = Product::factory()->forTenant($tenant)->create([
            'slug' => 'matrix-opts-product',
            'sku_root' => 'OPTS',
        ]);

        $service = app(ProductVariantService::class);
        $service->generateMatrix(
            $product,
            [
                ['name' => 'Color', 'values' => ['Rojo', 'Verde']],
                ['name' => 'Tamano', 'values' => ['S', 'M']],
            ],
            1000,
        );

        $optionSets = $product->variants()->get()->map(fn ($v) => $v->options)->all();

        $this->assertContains(['Color' => 'Rojo', 'Tamano' => 'S'], $optionSets);
        $this->assertContains(['Color' => 'Rojo', 'Tamano' => 'M'], $optionSets);
        $this->assertContains(['Color' => 'Verde', 'Tamano' => 'S'], $optionSets);
        $this->assertContains(['Color' => 'Verde', 'Tamano' => 'M'], $optionSets);
    }

    public function test_generates_single_variant_for_single_option_with_one_value(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'matrix-single-tenant']);
        $product = Product::factory()->forTenant($tenant)->create([
            'slug' => 'matrix-single-product',
            'sku_root' => 'SINGLE',
        ]);

        $service = app(ProductVariantService::class);
        $service->generateMatrix(
            $product,
            [['name' => 'Color', 'values' => ['Rojo']]],
            1000,
        );

        $this->assertCount(1, $product->variants()->get());
    }

    public function test_no_variants_generated_when_options_array_is_empty(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'matrix-empty-tenant']);
        $product = Product::factory()->forTenant($tenant)->create([
            'slug' => 'matrix-empty-product',
            'sku_root' => 'EMPTY',
        ]);

        $service = app(ProductVariantService::class);
        $service->generateMatrix($product, [], 1000);

        $this->assertCount(0, $product->variants()->get());
    }
}
