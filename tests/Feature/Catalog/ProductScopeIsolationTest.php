<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProductScopeIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function setTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    private function clearTenant(): void
    {
        app()->bind('currentTenant', fn (): ?Tenant => null);
    }

    public function test_belongs_to_tenant_scope_filters_products_to_current_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $productA1 = Product::factory()->forTenant($tenantA)->create();
        $productA2 = Product::factory()->forTenant($tenantA)->create();
        $productB1 = Product::factory()->forTenant($tenantB)->create();

        $this->setTenant($tenantA);

        $results = Product::all();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $productA1->id));
        $this->assertTrue($results->contains('id', $productA2->id));
        $this->assertFalse($results->contains('id', $productB1->id));
    }

    public function test_product_variants_can_be_queried_scopeless_but_product_join_filters_correctly(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $productA = Product::factory()->forTenant($tenantA)->create();
        $productB = Product::factory()->forTenant($tenantB)->create();

        $variantA = ProductVariant::factory()->forProduct($productA)->create();
        $variantB = ProductVariant::factory()->forProduct($productB)->create();

        // ProductVariant has no BelongsToTenant — direct queries return all rows regardless
        // of tenant context. This is correct: variants are scoped indirectly via Product.
        $this->clearTenant();

        $allVariants = ProductVariant::all();
        $this->assertCount(2, $allVariants);

        // When tenant A is resolved, Product scope filters correctly. Joining variants via
        // the Product relation returns only tenant A's variants.
        $this->setTenant($tenantA);

        $tenantAVariants = ProductVariant::whereHas(
            'product',
            fn ($q) => $q->where('tenant_id', $tenantA->id)
        )->get();

        $this->assertCount(1, $tenantAVariants);
        $this->assertTrue($tenantAVariants->contains('id', $variantA->id));
        $this->assertFalse($tenantAVariants->contains('id', $variantB->id));
    }
}
