<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * POS product grid endpoint tests.
 *
 * Verifies that:
 *  - Staff can browse active products with exact stock quantities.
 *  - Search by SKU works.
 *  - Unauthenticated requests are rejected with 401.
 *  - Exact available_quantity is exposed (vs storefront hiding it).
 */
final class PosProductsTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryService = app(InventoryService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{tenant: Tenant, branch: Branch, user: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenant);
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        return compact('tenant', 'branch', 'user');
    }

    private function productsUrl(Tenant $tenant, string $query = ''): string
    {
        return $this->tenantUrl($tenant, "api/v1/pos/products{$query}");
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_products_endpoint_returns_active_products_with_branch_stock(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user] = $this->setupTenant();

        $product = Product::factory()->forTenant($tenant)->create([
            'name' => 'Rosa Roja',
            'is_active' => true,
        ]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(1500)->create();

        // Seed 7 units into the branch
        $this->inventoryService->recordEntry($branch, $variant, 7, $user);

        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($user)
            ->getJson($this->productsUrl($tenant, "?branch_id={$branch->id}"));

        $response->assertOk();

        $data = $response->json('data');
        $this->assertNotEmpty($data, 'Expected at least one product in the response');

        // Find the variant in the response
        $foundVariant = collect($data)
            ->flatMap(fn ($p) => $p['variants'] ?? [])
            ->firstWhere('id', $variant->id);

        $this->assertNotNull($foundVariant, 'Variant not found in POS response');
        $this->assertSame(7, $foundVariant['available_quantity']);
        $this->assertTrue($foundVariant['in_stock']);
    }

    public function test_products_search_by_sku(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user] = $this->setupTenant();

        $product = Product::factory()->forTenant($tenant)->create(['is_active' => true]);
        $variant = ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'UNIQUE-SKU-XYZ',
        ]);
        $this->inventoryService->recordEntry($branch, $variant, 3, $user);

        // Create noise: another product that should NOT appear
        $otherProduct = Product::factory()->forTenant($tenant)->create(['is_active' => true]);
        ProductVariant::factory()->forProduct($otherProduct)->create(['sku' => 'OTHER-SKU-ABC']);

        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($user)
            ->getJson($this->productsUrl($tenant, "?branch_id={$branch->id}&search=UNIQUE-SKU-XYZ"));

        $response->assertOk();

        $skus = collect($response->json('data'))
            ->flatMap(fn ($p) => $p['variants'] ?? [])
            ->pluck('sku')
            ->all();

        $this->assertContains('UNIQUE-SKU-XYZ', $skus);
        $this->assertNotContains('OTHER-SKU-ABC', $skus);
    }

    public function test_products_requires_auth(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();

        $response = $this->getJson(
            $this->tenantUrl($tenant, "api/v1/pos/products?branch_id={$branch->id}")
        );

        $response->assertStatus(401);
    }

    public function test_products_requires_branch_id(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->setupTenant();

        $response = $this->actingAs($user)
            ->getJson($this->productsUrl($tenant));

        // branch_id is required — should fail validation (422)
        $response->assertStatus(422);
    }

    public function test_products_exact_quantity_visible_to_staff(): void
    {
        // This test explicitly confirms POS exposes exact counts (unlike the storefront).
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user] = $this->setupTenant();

        $product = Product::factory()->forTenant($tenant)->create(['is_active' => true]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(500)->create();
        $this->inventoryService->recordEntry($branch, $variant, 42, $user);

        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($user)
            ->getJson($this->productsUrl($tenant, "?branch_id={$branch->id}"));

        $response->assertOk();

        $foundVariant = collect($response->json('data'))
            ->flatMap(fn ($p) => $p['variants'] ?? [])
            ->firstWhere('id', $variant->id);

        $this->assertNotNull($foundVariant);
        // Exact count is present
        $this->assertSame(42, $foundVariant['available_quantity']);
        // Both convenience flags also present
        $this->assertArrayHasKey('in_stock', $foundVariant);
    }

    public function test_products_response_includes_branch_tax_config(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user] = $this->setupTenant();

        // setupTenant() already binds currentTenant — writeDefault() requires it.
        BranchSetting::writeDefault('tax', [
            'enabled'            => true,
            'rate_bps'           => 1300,
            'prices_include_tax' => false,
        ]);

        $response = $this->actingAs($user)
            ->getJson($this->productsUrl($tenant, "?branch_id={$branch->id}"))
            ->assertOk();

        $response->assertJsonPath('tax.enabled', true)
            ->assertJsonPath('tax.rate_bps', 1300)
            ->assertJsonPath('tax.prices_include_tax', false);
    }

    public function test_inactive_products_are_excluded_from_pos_grid(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user] = $this->setupTenant();

        $inactive = Product::factory()->forTenant($tenant)->create([
            'name' => 'Inactive Prod',
            'is_active' => false,
        ]);
        ProductVariant::factory()->forProduct($inactive)->create();

        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($user)
            ->getJson($this->productsUrl($tenant, "?branch_id={$branch->id}&search=Inactive+Prod"));

        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertNotContains('Inactive Prod', $names);
    }
}
