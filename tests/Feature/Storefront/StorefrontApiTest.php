<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Storefront public API tests.
 *
 * All requests are unauthenticated (no actingAs). Tenant resolution happens via
 * the subdomain in the full URL: http://{slug}.eternova.app/api/v1/storefront/...
 */
final class StorefrontApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Branch $mainBranchA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable tenancy cache so factory-created tenants resolve immediately
        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        $this->tenantA = Tenant::factory()->create([
            'slug' => 'storefront-tenant-a',
            'status' => 'active',
            'brand_extra' => ['whatsapp_number' => '50312345678'],
        ]);

        $this->mainBranchA = Branch::factory()->forTenant($this->tenantA)->main()->create();

        $this->tenantB = Tenant::factory()->create([
            'slug' => 'storefront-tenant-b',
            'status' => 'active',
        ]);
    }

    // =========================================================================
    // Auth + access
    // =========================================================================

    public function test_storefront_products_accessible_without_auth(): void
    {
        Product::factory()->forTenant($this->tenantA)->create(['slug' => 'no-auth-product']);

        $this->getJson($this->storefrontUrl($this->tenantA, '/api/v1/storefront/products'))
            ->assertOk();
    }

    // =========================================================================
    // Active-only filtering
    // =========================================================================

    public function test_storefront_returns_only_active_products(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'active-product',
            'is_active' => true,
        ]);

        Product::factory()->forTenant($this->tenantA)->inactive()->create([
            'slug' => 'inactive-product',
        ]);

        $response = $this->getJson($this->storefrontUrl($this->tenantA, '/api/v1/storefront/products'))
            ->assertOk();

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('active-product', $slugs);
        $this->assertNotContains('inactive-product', $slugs);
    }

    // =========================================================================
    // Security: cost_price_cents must never leak
    // =========================================================================

    public function test_storefront_product_resource_never_exposes_cost_price(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'cost-check-product',
            'cost_price_cents' => 999,
        ]);

        $response = $this->getJson($this->storefrontUrl($this->tenantA, '/api/v1/storefront/products'))
            ->assertOk();

        $this->assertStringNotContainsString(
            'cost_price',
            $response->content(),
            'cost_price_cents must never appear in storefront product list response'
        );
    }

    public function test_storefront_variant_never_exposes_cost_price(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'variant-cost-check',
            'is_active' => true,
        ]);

        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'COST-CHK-01',
            'cost_price_cents' => 555,
        ]);

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products/variant-cost-check')
        )->assertOk();

        $this->assertStringNotContainsString(
            'cost_price',
            $response->content(),
            'cost_price_cents must never appear in storefront product detail response'
        );
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    public function test_storefront_is_tenant_isolated(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'tenant-a-only-product',
            'is_active' => true,
        ]);

        Product::factory()->forTenant($this->tenantB)->create([
            'slug' => 'tenant-b-only-product',
            'is_active' => true,
        ]);

        // Request scoped to tenant B's subdomain
        $response = $this->getJson(
            $this->storefrontUrl($this->tenantB, '/api/v1/storefront/products')
        )->assertOk();

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('tenant-b-only-product', $slugs);
        $this->assertNotContains('tenant-a-only-product', $slugs);
    }

    // =========================================================================
    // Product detail by slug
    // =========================================================================

    public function test_storefront_product_detail_by_slug(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'detail-slug-test',
            'is_active' => true,
            'gallery' => ['https://cdn.example.com/img1.jpg'],
        ]);

        $variant = ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'DETAIL-V1',
        ]);

        BranchInventory::factory()
            ->forBranch($this->mainBranchA)
            ->forVariant($variant)
            ->withStock(10)
            ->create();

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products/detail-slug-test')
        )->assertOk();

        $data = $response->json('data');
        $this->assertSame('detail-slug-test', $data['slug']);
        $this->assertArrayHasKey('variants', $data);
        $this->assertArrayHasKey('gallery', $data);
        $this->assertCount(1, $data['variants']);
        $this->assertSame('DETAIL-V1', $data['variants'][0]['sku']);
        $this->assertTrue($data['variants'][0]['in_stock']);
    }

    public function test_storefront_product_detail_returns_404_for_inactive(): void
    {
        Product::factory()->forTenant($this->tenantA)->inactive()->create([
            'slug' => 'inactive-detail-product',
        ]);

        $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products/inactive-detail-product')
        )->assertNotFound();
    }

    // =========================================================================
    // Featured products
    // =========================================================================

    public function test_storefront_featured_returns_only_featured(): void
    {
        Product::factory()->forTenant($this->tenantA)->featured()->create([
            'slug' => 'featured-one',
        ]);

        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'not-featured',
            'is_active' => true,
            'is_featured' => false,
        ]);

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products/featured')
        )->assertOk();

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('featured-one', $slugs);
        $this->assertNotContains('not-featured', $slugs);
    }

    // =========================================================================
    // Categories
    // =========================================================================

    public function test_storefront_categories_returns_active_tree(): void
    {
        $root = Category::factory()->forTenant($this->tenantA)->create([
            'slug' => 'root-category-test',
            'is_active' => true,
            'parent_id' => null,
        ]);

        Category::factory()->forTenant($this->tenantA)->create([
            'slug' => 'child-category-test',
            'is_active' => true,
            'parent_id' => $root->id,
        ]);

        Category::factory()->forTenant($this->tenantA)->create([
            'slug' => 'inactive-category-test',
            'is_active' => false,
            'parent_id' => null,
        ]);

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/categories')
        )->assertOk();

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('root-category-test', $slugs);
        $this->assertNotContains('inactive-category-test', $slugs);

        // Verify children are nested under their parent
        $rootInResponse = collect($response->json('data'))
            ->firstWhere('slug', 'root-category-test');

        $this->assertNotNull($rootInResponse);
        $childSlugs = array_column($rootInResponse['children'], 'slug');
        $this->assertContains('child-category-test', $childSlugs);
    }

    // =========================================================================
    // Filters
    // =========================================================================

    public function test_storefront_filters_by_category_slug(): void
    {
        $category = Category::factory()->forTenant($this->tenantA)->create([
            'slug' => 'rosas-filter-test',
            'is_active' => true,
        ]);

        $inCategory = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'in-cat-product',
            'is_active' => true,
        ]);

        $notInCategory = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'not-in-cat-product',
            'is_active' => true,
        ]);

        $inCategory->categories()->attach($category->id);

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products?category_slug=rosas-filter-test')
        )->assertOk();

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('in-cat-product', $slugs);
        $this->assertNotContains('not-in-cat-product', $slugs);
    }

    public function test_storefront_search_filters_products(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'rosa-carmesi-search-test',
            'name' => 'Rosa Carmesi de Lujo',
            'is_active' => true,
        ]);

        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'peluche-search-test',
            'name' => 'Peluche Osito',
            'is_active' => true,
        ]);

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products?search=carmesi')
        )->assertOk();

        $slugs = array_column($response->json('data'), 'slug');
        $this->assertContains('rosa-carmesi-search-test', $slugs);
        $this->assertNotContains('peluche-search-test', $slugs);
    }

    // =========================================================================
    // Tenant branding endpoint
    // =========================================================================

    public function test_storefront_tenant_endpoint_returns_branding_with_whatsapp(): void
    {
        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/tenant')
        )->assertOk();

        $data = $response->json('data');
        $this->assertArrayHasKey('business_name', $data);
        $this->assertArrayHasKey('logo_url', $data);
        $this->assertArrayHasKey('currency', $data);
        $this->assertArrayHasKey('country_code', $data);
        $this->assertSame('50312345678', $data['whatsapp_number']);
    }

    public function test_storefront_tenant_endpoint_never_exposes_internal_fields(): void
    {
        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/tenant')
        )->assertOk();

        $content = $response->content();
        $this->assertStringNotContainsString('"email"', $content, 'email must not be exposed');
        $this->assertStringNotContainsString('"status"', $content, 'status must not be exposed');
        $this->assertStringNotContainsString('trial_ends_at', $content, 'trial_ends_at must not be exposed');
    }

    // =========================================================================
    // Stock availability
    // =========================================================================

    public function test_variant_in_stock_reflects_main_branch_inventory(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'stock-test-product',
            'is_active' => true,
        ]);

        $variantInStock = ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'STOCK-IN-01',
        ]);

        $variantOutOfStock = ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'STOCK-OUT-01',
        ]);

        // Only the first variant has inventory
        BranchInventory::factory()
            ->forBranch($this->mainBranchA)
            ->forVariant($variantInStock)
            ->withStock(5)
            ->create();

        BranchInventory::factory()
            ->forBranch($this->mainBranchA)
            ->forVariant($variantOutOfStock)
            ->withStock(0)
            ->create();

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products/stock-test-product')
        )->assertOk();

        $variants = $response->json('data.variants');
        $inStockVariant = collect($variants)->firstWhere('sku', 'STOCK-IN-01');
        $outOfStockVariant = collect($variants)->firstWhere('sku', 'STOCK-OUT-01');

        $this->assertNotNull($inStockVariant);
        $this->assertTrue($inStockVariant['in_stock']);

        $this->assertNotNull($outOfStockVariant);
        $this->assertFalse($outOfStockVariant['in_stock']);
    }

    public function test_variant_with_no_inventory_row_shows_out_of_stock(): void
    {
        $product = Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'no-inventory-row-product',
            'is_active' => true,
        ]);

        ProductVariant::factory()->forProduct($product)->create([
            'sku' => 'NO-ROW-01',
        ]);

        // No BranchInventory row created for this variant

        $response = $this->getJson(
            $this->storefrontUrl($this->tenantA, '/api/v1/storefront/products/no-inventory-row-product')
        )->assertOk();

        $variant = $response->json('data.variants.0');
        $this->assertFalse($variant['in_stock']);
    }

    // =========================================================================
    // Unknown tenant returns 404
    // =========================================================================

    public function test_unknown_tenant_subdomain_returns_404(): void
    {
        $baseDomain = config('tenancy.base_domain', 'eternova.app');

        $this->getJson("http://nonexistent-ghost-tenant.{$baseDomain}/api/v1/storefront/products")
            ->assertNotFound();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a full URL for a storefront request, using the tenant's subdomain so
     * EnsureTenant can resolve the tenant without authentication.
     */
    private function storefrontUrl(Tenant $tenant, string $path): string
    {
        $baseDomain = config('tenancy.base_domain', 'eternova.app');
        $path = ltrim($path, '/');

        return "http://{$tenant->slug}.{$baseDomain}/{$path}";
    }
}
