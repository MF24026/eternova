<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductVariantSkuUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_sku_is_unique_within_product_but_not_globally(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $productA = Product::factory()->forTenant($tenantA)->create();
        $productB = Product::factory()->forTenant($tenantB)->create();

        // Both products can have a variant with the same SKU — UNIQUE is (product_id, sku)
        ProductVariant::factory()->forProduct($productA)->create(['sku' => 'SHARED-SKU-001']);
        ProductVariant::factory()->forProduct($productB)->create(['sku' => 'SHARED-SKU-001']);

        $this->assertDatabaseHas('product_variants', ['product_id' => $productA->id, 'sku' => 'SHARED-SKU-001']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $productB->id, 'sku' => 'SHARED-SKU-001']);
    }

    public function test_duplicate_sku_within_same_product_throws(): void
    {
        $tenant = Tenant::factory()->create();
        $product = Product::factory()->forTenant($tenant)->create();

        ProductVariant::factory()->forProduct($product)->create(['sku' => 'DUPE-SKU-999']);

        $this->expectException(QueryException::class);

        // Same product, same SKU — DB constraint violation
        ProductVariant::factory()->forProduct($product)->create(['sku' => 'DUPE-SKU-999']);
    }
}
