<?php

declare(strict_types=1);

namespace Tests\Feature\Storefront;

use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Sitemap + robots.txt endpoint tests.
 *
 * Both endpoints are server-rendered, tenant-scoped, and public (no auth).
 * Tenant resolution happens via the subdomain in the full request URL, matching
 * the same pattern used in StorefrontApiTest::storefrontUrl().
 */
final class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        $this->tenantA = Tenant::factory()->create([
            'slug' => 'sitemap-tenant-a',
            'status' => 'active',
        ]);

        $this->tenantB = Tenant::factory()->create([
            'slug' => 'sitemap-tenant-b',
            'status' => 'active',
        ]);
    }

    // =========================================================================
    // sitemap.xml content
    // =========================================================================

    public function test_sitemap_returns_xml_with_active_products(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'rosa-roja',
            'is_active' => true,
        ]);

        $response = $this->get($this->tenantWebUrl($this->tenantA, '/sitemap.xml'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertStringContainsString('rosa-roja', $response->content());
        $this->assertStringContainsString('<urlset', $response->content());
    }

    public function test_sitemap_excludes_inactive_products(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'active-arreglo',
            'is_active' => true,
        ]);

        Product::factory()->forTenant($this->tenantA)->inactive()->create([
            'slug' => 'inactive-arreglo',
        ]);

        $response = $this->get($this->tenantWebUrl($this->tenantA, '/sitemap.xml'));

        $response->assertOk();
        $this->assertStringContainsString('active-arreglo', $response->content());
        $this->assertStringNotContainsString('inactive-arreglo', $response->content());
    }

    public function test_sitemap_is_tenant_scoped(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'product-only-tenant-a',
            'is_active' => true,
        ]);

        Product::factory()->forTenant($this->tenantB)->create([
            'slug' => 'product-only-tenant-b',
            'is_active' => true,
        ]);

        $responseA = $this->get($this->tenantWebUrl($this->tenantA, '/sitemap.xml'));
        $responseB = $this->get($this->tenantWebUrl($this->tenantB, '/sitemap.xml'));

        $responseA->assertOk();
        $responseB->assertOk();

        $this->assertStringContainsString('product-only-tenant-a', $responseA->content());
        $this->assertStringNotContainsString('product-only-tenant-b', $responseA->content());

        $this->assertStringContainsString('product-only-tenant-b', $responseB->content());
        $this->assertStringNotContainsString('product-only-tenant-a', $responseB->content());
    }

    public function test_sitemap_includes_home_and_products_urls(): void
    {
        $response = $this->get($this->tenantWebUrl($this->tenantA, '/sitemap.xml'));

        $response->assertOk();

        $baseDomain = config('tenancy.base_domain', 'eternova.app');
        $baseUrl = "http://{$this->tenantA->slug}.{$baseDomain}";

        // Home URL
        $this->assertStringContainsString("<loc>{$baseUrl}/</loc>", $response->content());
        // Products list URL
        $this->assertStringContainsString("<loc>{$baseUrl}/products</loc>", $response->content());
    }

    public function test_sitemap_includes_lastmod_for_products(): void
    {
        Product::factory()->forTenant($this->tenantA)->create([
            'slug' => 'arreglo-con-fecha',
            'is_active' => true,
        ]);

        $response = $this->get($this->tenantWebUrl($this->tenantA, '/sitemap.xml'));

        $response->assertOk();
        $this->assertStringContainsString('<lastmod>', $response->content());
        $this->assertStringContainsString('<changefreq>weekly</changefreq>', $response->content());
    }

    // =========================================================================
    // robots.txt
    // =========================================================================

    public function test_robots_txt_returns_plaintext_with_sitemap_reference(): void
    {
        $response = $this->get($this->tenantWebUrl($this->tenantA, '/robots.txt'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        $content = $response->content();
        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Allow: /', $content);
        $this->assertStringContainsString('Sitemap:', $content);
        $this->assertStringContainsString('/sitemap.xml', $content);
    }

    public function test_robots_and_sitemap_resolve_per_tenant_subdomain(): void
    {
        $baseDomain = config('tenancy.base_domain', 'eternova.app');

        $robotsA = $this->get($this->tenantWebUrl($this->tenantA, '/robots.txt'));
        $robotsB = $this->get($this->tenantWebUrl($this->tenantB, '/robots.txt'));

        $robotsA->assertOk();
        $robotsB->assertOk();

        $expectedSitemapA = "http://{$this->tenantA->slug}.{$baseDomain}/sitemap.xml";
        $expectedSitemapB = "http://{$this->tenantB->slug}.{$baseDomain}/sitemap.xml";

        $this->assertStringContainsString($expectedSitemapA, $robotsA->content());
        $this->assertStringContainsString($expectedSitemapB, $robotsB->content());
        // Each tenant's robots.txt references only its own sitemap.
        $this->assertStringNotContainsString('sitemap-tenant-b', $robotsA->content());
        $this->assertStringNotContainsString('sitemap-tenant-a', $robotsB->content());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a full URL for a tenant web route (non-API) using the subdomain.
     * EnsureTenant resolves the tenant from the Host header built into this URL.
     */
    private function tenantWebUrl(Tenant $tenant, string $path): string
    {
        $baseDomain = config('tenancy.base_domain', 'eternova.app');
        $path = ltrim($path, '/');

        return "http://{$tenant->slug}.{$baseDomain}/{$path}";
    }
}
