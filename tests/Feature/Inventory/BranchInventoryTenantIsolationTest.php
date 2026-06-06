<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * These tests are the foundational isolation guarantees for the Inventory module.
 *
 * They verify that:
 *  1. The BelongsToTenant global scope silently filters cross-tenant rows from reads.
 *  2. API endpoints respect tenant boundaries — a user of tenant A cannot access
 *     or mutate tenant B's inventory data.
 */
final class BranchInventoryTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryService::class);
    }

    public function test_belongs_to_tenant_scope_filters_branch_inventory_correctly(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->forTenant($tenantA)->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantA);
        $productA = Product::factory()->forTenant($tenantA)->create();
        $variantA = ProductVariant::factory()->forProduct($productA)->create();
        $inventoryA = BranchInventory::create([
            'tenant_id' => $tenantA->id,
            'branch_id' => $branchA->id,
            'product_variant_id' => $variantA->id,
            'quantity' => 10,
            'reserved' => 0,
        ]);

        app()->instance('currentTenant', $tenantB);
        $productB = Product::factory()->forTenant($tenantB)->create();
        $variantB = ProductVariant::factory()->forProduct($productB)->create();
        BranchInventory::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'product_variant_id' => $variantB->id,
            'quantity' => 50,
            'reserved' => 0,
        ]);

        // Under tenant A context, only tenant A's inventory row is visible
        app()->instance('currentTenant', $tenantA);
        $visible = BranchInventory::all();

        $this->assertCount(1, $visible);
        $this->assertTrue($visible->contains('id', $inventoryA->id));
    }

    public function test_tenant_a_cannot_see_tenant_b_inventory_via_api(): void
    {
        config([
            'tenancy.resolver' => 'subdomain',
            'tenancy.base_domain' => 'eternova.app',
        ]);

        $tenantA = Tenant::factory()->create(['slug' => 'shop-a', 'status' => 'active', 'trial_ends_at' => null]);
        $tenantB = Tenant::factory()->create(['slug' => 'shop-b', 'status' => 'active', 'trial_ends_at' => null]);

        $userA = User::factory()->forTenant($tenantA, role: 'owner')->create();
        $branchA = Branch::factory()->forTenant($tenantA)->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        // Create inventory in both tenants
        app()->instance('currentTenant', $tenantA);
        $productA = Product::factory()->forTenant($tenantA)->create();
        $variantA = ProductVariant::factory()->forProduct($productA)->create();

        app()->instance('currentTenant', $tenantB);
        $productB = Product::factory()->forTenant($tenantB)->create();
        $variantB = ProductVariant::factory()->forProduct($productB)->create();
        $invB = BranchInventory::create([
            'tenant_id' => $tenantB->id,
            'branch_id' => $branchB->id,
            'product_variant_id' => $variantB->id,
            'quantity' => 100,
            'reserved' => 0,
        ]);

        // User A hits the API under tenant A's subdomain
        $response = $this->actingAs($userA)
            ->withHeaders(['Host' => 'shop-a.eternova.app'])
            ->getJson('/api/v1/inventory/'.$invB->id);

        // Either 404 (scope filtered it out via route model binding) or resource.not_found
        $response->assertStatus(404);
    }

    public function test_user_of_tenant_a_cannot_record_movement_in_tenant_b_branch(): void
    {
        config([
            'tenancy.resolver' => 'subdomain',
            'tenancy.base_domain' => 'eternova.app',
            'tenancy.cache.enabled' => false,
        ]);

        $tenantA = Tenant::factory()->create(['slug' => 'shop-aa', 'status' => 'active', 'trial_ends_at' => null]);
        $tenantB = Tenant::factory()->create(['slug' => 'shop-bb', 'status' => 'active', 'trial_ends_at' => null]);

        $userA = User::factory()->forTenant($tenantA, role: 'owner')->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantB);
        $productB = Product::factory()->forTenant($tenantB)->create();
        $variantB = ProductVariant::factory()->forProduct($productB)->create();

        // User A makes an authenticated request scoped to shop-aa.
        // The validation custom rule checks that branch_id belongs to shop-aa's tenant,
        // which it does not (branchB belongs to shop-bb) → 422 validation error.
        $response = $this->actingAs($userA)
            ->withHeaders(['Host' => 'shop-aa.eternova.app'])
            ->postJson('/api/v1/inventory/movements', [
                'branch_id' => $branchB->id,
                'product_variant_id' => $variantB->id,
                'type' => 'entry',
                'quantity' => 10,
            ]);

        // Either 422 (validation rejects cross-tenant branch) or 403/404 (policy/scope).
        // Both are acceptable — the important thing is it is NOT 201.
        $this->assertContains($response->status(), [422, 403, 404]);
    }
}
