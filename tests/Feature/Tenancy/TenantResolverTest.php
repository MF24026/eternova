<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Http\Middleware\EnsureTenant;
use App\Modules\Tenancy\Models\ReservedSubdomain;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Feature tests for the EnsureTenant middleware resolver.
 *
 * Each test registers a temporary route under the 'tenant' middleware alias,
 * then issues a crafted HTTP request with the desired Host header. The route
 * echoes back the resolved tenant slug so we can assert resolution without
 * needing auth, controllers, or API resources.
 *
 * Host isolation: Laravel's test client extracts the host from the URL passed
 * to json()/get() — so we pass full URLs (http://rosa-eterna.eternova.app/...)
 * rather than using withServerVariables(HTTP_HOST) which gets overridden by the
 * URL's own host component in Symfony's SymfonyRequest::create().
 *
 * Database: uses RefreshDatabase with a PID-unique MySQL database so that
 * concurrent parallel agents (issue #8, #9) running migrate:fresh on the
 * shared "testing" DB cannot destroy our schema mid-run.
 *
 * Cache isolation: each test disables the resolver slug cache + calls
 * Cache::flush() in setUp() so stale in-process array-cache entries from
 * previous tests do not cause false 404s.
 */
final class TenantResolverTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Switch to a PID-unique MySQL database before RefreshDatabase runs migrate:fresh.
     *
     * Three parallel agents (E3, E4, E5) each run migrate:fresh on the "testing"
     * DB, causing deadlocks and table-drops mid-run. Each agent gets its own
     * database keyed by PID, eliminating all concurrent interference.
     *
     * We also reset RefreshDatabaseState::$migrated = false so that RefreshDatabase
     * re-runs migrate:fresh on the NEW database even if a prior test class already
     * ran it on the shared "testing" DB in the same process.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $originalDb = (string) $this->app['config']->get('database.connections.mysql.database');
        $dbName = 'testing_tenancy_'.getmypid();

        try {
            // Use the current connection to create our dedicated DB (idempotent).
            $this->app['db']->connection('mysql')
                ->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (\Throwable) {
            // Insufficient privileges (e.g. CI, where the DB user cannot CREATE
            // databases): fall back to the already-configured test database rather
            // than a bare "testing" that may not exist / be accessible.
            $dbName = $originalDb;
        }

        $this->app['config']->set('database.connections.mysql.database', $dbName);
        $this->app['db']->purge('mysql');

        // Force RefreshDatabase to re-run migrate:fresh on the new database.
        // Static $migrated is set to true by prior test classes in the same process.
        RefreshDatabaseState::$migrated = false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Disable slug resolution cache for all tests. Stale array-cache entries
        // (the cache driver is "array" in tests, which survives across tests in the
        // same PHP process) can cause false 404s for valid slugs.
        config(['tenancy.cache.enabled' => false]);

        // Also clear any cached reserved-subdomain lookups from prior tests.
        Cache::flush();
    }

    // -------------------------------------------------------------------------
    // Subdomain mode
    // -------------------------------------------------------------------------

    public function test_subdomain_mode_resolves_tenant_correctly(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'eternova.app']);

        Tenant::factory()->create(['slug' => 'rosa-eterna', 'status' => 'active', 'trial_ends_at' => null]);

        $this->registerTenantRoute();

        // Use the full URL so Symfony extracts 'rosa-eterna.eternova.app' as Host.
        $response = $this->getJson('http://rosa-eterna.eternova.app/_test/tenant');

        $response->assertOk()->assertJson(['slug' => 'rosa-eterna']);
    }

    public function test_subdomain_mode_handles_localhost_dev_pattern(): void
    {
        config([
            'tenancy.resolver' => 'subdomain',
            'tenancy.base_domain' => 'eternova.app',
            'tenancy.localhost_tlds' => ['localhost', 'test'],
        ]);

        Tenant::factory()->create(['slug' => 'rosa-eterna', 'status' => 'active', 'trial_ends_at' => null]);

        $this->registerTenantRoute();

        // Port is included in the URL; the middleware must strip it from the Host.
        $response = $this->getJson('http://rosa-eterna.eternova.localhost:8080/_test/tenant');

        $response->assertOk()->assertJson(['slug' => 'rosa-eterna']);
    }

    public function test_subdomain_mode_treats_root_domain_as_platform_level(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'eternova.app']);

        $this->registerTenantRoute();

        // 2 labels only — middleware must return null → outer abort(404).
        $response = $this->getJson('http://eternova.app/_test/tenant');

        $response->assertNotFound();
    }

    public function test_subdomain_mode_returns_404_for_unknown_slug(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'eternova.app']);

        // No tenant created — findBySlug returns null → abort(404).
        $this->registerTenantRoute();

        $response = $this->getJson('http://does-not-exist.eternova.app/_test/tenant');

        $response->assertNotFound();
    }

    public function test_subdomain_mode_returns_404_for_reserved_slug(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'eternova.app']);

        ReservedSubdomain::factory()->system()->create(['subdomain' => 'admin']);

        $this->registerTenantRoute();

        $response = $this->getJson('http://admin.eternova.app/_test/tenant');

        $response->assertNotFound();
    }

    public function test_subdomain_mode_returns_404_for_invalid_rfc1035_slug(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'eternova.app']);

        $this->registerTenantRoute();

        // Each of these slugs violates at least one RFC 1035 / config constraint.
        $invalidSlugs = [
            '-foo',              // leading dash
            'foo-',              // trailing dash
            'FOO',               // uppercase
            'ab',                // too short (min 3)
            str_repeat('a', 64), // too long (max 63)
        ];

        foreach ($invalidSlugs as $slug) {
            $response = $this->getJson("http://{$slug}.eternova.app/_test/tenant");

            $response->assertNotFound(
                "Expected 404 for invalid slug '{$slug}' but got {$response->status()}"
            );
        }
    }

    // -------------------------------------------------------------------------
    // Path mode
    // -------------------------------------------------------------------------

    public function test_path_mode_resolves_tenant_and_rewrites_url(): void
    {
        config([
            'tenancy.resolver' => 'path',
            'tenancy.path_prefix' => 't',
            'tenancy.base_domain' => 'eternova.app',
        ]);

        Tenant::factory()->create(['slug' => 'rosa-eterna', 'status' => 'active', 'trial_ends_at' => null]);

        // Path mode routes use a prefix group: /t/{slug}/... where the middleware
        // resolves the tenant from the slug segment. The URI rewrite happens inside
        // EnsureTenant so downstream routes see the clean path without the prefix.
        //
        // In tests, we register the route WITH the full prefix so the router can
        // match it. The EnsureTenant middleware is applied as a route middleware.
        // The route handler verifies that:
        //   a) the tenant was resolved from the path
        //   b) the request->path() returned the REWRITTEN path (without prefix)
        Route::middleware('tenant')->get('/t/rosa-eterna/admin/dashboard', static function (Request $r) {
            return response()->json([
                'slug' => current_tenant()?->slug,
                'rewritten_path' => $r->path(),
            ]);
        })->name('test.tenant.path');

        $response = $this->getJson('http://eternova.app/t/rosa-eterna/admin/dashboard');

        $response->assertOk()
            ->assertJson(['slug' => 'rosa-eterna'])
            // After rewrite, request path should be the clean downstream path.
            ->assertJson(['rewritten_path' => 'admin/dashboard']);
    }

    // -------------------------------------------------------------------------
    // Domain mode
    // -------------------------------------------------------------------------

    public function test_domain_mode_resolves_via_tenant_domains_table(): void
    {
        config([
            'tenancy.resolver' => 'domain',
            'tenancy.base_domain' => 'eternova.app',
        ]);

        $tenant = Tenant::factory()->create([
            'slug' => 'rosa-eterna',
            'status' => 'active',
            'trial_ends_at' => null,
        ]);

        TenantDomain::factory()->verified()->create([
            'tenant_id' => $tenant->id,
            'domain' => 'rosaeterna.com',
        ]);

        $this->registerTenantRoute();

        $response = $this->getJson('http://rosaeterna.com/_test/tenant');

        $response->assertOk()->assertJson(['slug' => 'rosa-eterna']);
    }

    public function test_domain_mode_falls_back_to_subdomain(): void
    {
        config([
            'tenancy.resolver' => 'domain',
            'tenancy.base_domain' => 'eternova.app',
        ]);

        Tenant::factory()->create(['slug' => 'rosa-eterna', 'status' => 'active', 'trial_ends_at' => null]);

        // No TenantDomain row, but host is on our own base_domain → fall back to subdomain.
        $this->registerTenantRoute();

        $response = $this->getJson('http://rosa-eterna.eternova.app/_test/tenant');

        $response->assertOk()->assertJson(['slug' => 'rosa-eterna']);
    }

    // -------------------------------------------------------------------------
    // Tenant status gates
    // -------------------------------------------------------------------------

    public function test_suspended_tenant_returns_503(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'eternova.app']);

        Tenant::factory()->suspended()->create(['slug' => 'suspended-shop', 'trial_ends_at' => null]);

        $this->registerTenantRoute();

        $response = $this->getJson('http://suspended-shop.eternova.app/_test/tenant');

        $response->assertStatus(503)
            ->assertJson(['error' => 'tenant_unavailable']);
    }

    public function test_expired_trial_passes_through_with_header(): void
    {
        config(['tenancy.resolver' => 'subdomain', 'tenancy.base_domain' => 'eternova.app']);

        // Trial ended 5 days ago, no active subscription → soft trial-expiry gate.
        Tenant::factory()->create([
            'slug' => 'expired-trial-shop',
            'status' => 'active',
            'trial_ends_at' => now()->subDays(5),
        ]);

        $this->registerTenantRoute();

        $response = $this->getJson('http://expired-trial-shop.eternova.app/_test/tenant');

        // Must NOT 402 — hard gate lives in plan gating (issue #11/#12).
        $response->assertOk()
            ->assertJson(['slug' => 'expired-trial-shop'])
            ->assertHeader('X-Tenant-Trial-Expired', '1');
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    /**
     * Register /_test/tenant under the 'tenant' middleware alias.
     *
     * Returns the resolved tenant's slug so tests can assert resolution without
     * touching auth, controllers, or resources.
     */
    private function registerTenantRoute(): void
    {
        Route::middleware('tenant')->get('/_test/tenant', static function () {
            return response()->json(['slug' => current_tenant()?->slug]);
        })->name('test.tenant');
    }
}
