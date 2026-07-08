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
 * Per-variant is_active toggle: inactive variants stay configured but are hidden
 * from the public storefront and the POS.
 */
final class VariantActiveTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, branch: Branch, product: Product, active: ProductVariant, inactive: ProductVariant, owner: User}
     */
    private function makeContext(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['is_active' => true]);
        $active = ProductVariant::factory()->forProduct($product)->withPrice(1500)
            ->create(['options' => ['Talla' => 'S'], 'is_active' => true]);
        $inactive = ProductVariant::factory()->forProduct($product)->withPrice(1600)
            ->create(['options' => ['Talla' => 'M'], 'is_active' => false]);

        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app(InventoryService::class)->recordEntry($branch, $active, 10, $owner);
        app(InventoryService::class)->recordEntry($branch, $inactive, 10, $owner);
        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'product', 'active', 'inactive', 'owner');
    }

    public function test_updating_a_variant_can_deactivate_it(): void
    {
        ['tenant' => $tenant, 'product' => $product, 'active' => $active, 'owner' => $owner] = $this->makeContext();

        $this->actingAs($owner)
            ->patchJson($this->tenantUrl($tenant, "api/v1/products/{$product->id}/variants/{$active->id}"), [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($active->fresh()->is_active);
    }

    public function test_storefront_detail_hides_inactive_variants(): void
    {
        ['tenant' => $tenant, 'product' => $product, 'active' => $active] = $this->makeContext();

        $response = $this->getJson($this->tenantUrl($tenant, "api/v1/storefront/products/{$product->slug}"))
            ->assertOk();

        $variantIds = array_column($response->json('data.variants'), 'id');
        $this->assertSame([$active->id], $variantIds);
    }

    public function test_pos_products_hide_inactive_variants(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'product' => $product, 'active' => $active, 'owner' => $owner] = $this->makeContext();

        $response = $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, "api/v1/pos/products?branch_id={$branch->id}&per_page=100"))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $product->id);
        $this->assertNotNull($row);
        $variantIds = array_column($row['variants'], 'id');
        $this->assertSame([$active->id], $variantIds);
    }
}
