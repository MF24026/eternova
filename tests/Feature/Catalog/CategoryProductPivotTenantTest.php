<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryProductPivotTenantTest extends TestCase
{
    use RefreshDatabase;

    private function setTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public function test_category_product_pivot_auto_sets_tenant_id_on_attach(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setTenant($tenant);

        $product = Product::factory()->forTenant($tenant)->create();
        $category = Category::factory()->forTenant($tenant)->create();

        $product->categories()->attach($category->id, ['sort_order' => 5]);

        $this->assertDatabaseHas('category_product', [
            'product_id' => $product->id,
            'category_id' => $category->id,
            'tenant_id' => $tenant->id,
            'sort_order' => 5,
        ]);
    }
}
