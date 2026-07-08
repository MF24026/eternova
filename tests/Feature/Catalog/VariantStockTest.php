<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Per-variant stock visibility: the product detail exposes available stock keyed by
 * branch so the admin overlay can show it per selected branch (reserved reduces it).
 */
final class VariantStockTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    public function test_product_detail_exposes_available_stock_per_branch(): void
    {
        $tenant = Tenant::factory()->create();
        $main = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $second = Branch::factory()->forTenant($tenant)->create(['is_main' => false]);
        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['is_active' => true]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(1500)
            ->create(['options' => ['Talla' => 'S'], 'min_stock_alert' => 5]);

        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        $inventory = app(InventoryService::class);
        $inventory->recordEntry($main, $variant, 10, $owner);
        $inventory->recordEntry($second, $variant, 4, $owner);
        $inventory->reserve($main, $variant, 3); // main available -> 7
        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, "api/v1/products/{$product->id}"))
            ->assertOk();

        $byBranch = $response->json('data.variants.0.available_by_branch');
        $this->assertSame(7, $byBranch[(string) $main->id]);
        $this->assertSame(4, $byBranch[(string) $second->id]);
        $response->assertJsonPath('data.variants.0.min_stock_alert', 5);
    }

    public function test_single_variant_update_response_omits_stock_map(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['is_active' => true]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(1500)
            ->create(['options' => ['Talla' => 'S']]);

        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app(InventoryService::class)->recordEntry($branch, $variant, 10, $owner);
        app()->instance('currentTenant', $tenant);

        $this->actingAs($owner)
            ->patchJson($this->tenantUrl($tenant, "api/v1/products/{$product->id}/variants/{$variant->id}"), [
                'sku' => 'UPDATED-SKU',
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.available_by_branch');
    }
}
