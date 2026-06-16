<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Validates multi-tenant access isolation at the API boundary.
 *
 * Architectural note on how isolation works in Eternova:
 *
 *   1. EnsureTenant middleware resolves the tenant from the request (subdomain/path/domain).
 *      It does NOT check user membership — that is intentional. The middleware is responsible
 *      for resolving context, not for enforcing who can access what.
 *
 *   2. BelongsToTenant global scope automatically filters ALL queries to the current tenant.
 *      A request to tenant-b.eternova.app will see ONLY tenant B's data — not tenant A's.
 *
 *   3. Policies enforce role-based access within the resolved tenant context.
 *      A user who is not a member of tenant B cannot pass the Policy's assertHasRole() check
 *      on any protected endpoint.
 *
 * These tests verify layer 1 (middleware resolves tenant) and layer 2 (scope filters data).
 * Policy tests (layer 3) are in PolicyTest.php.
 */
final class TenantAccessIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.cache.enabled' => false]);
        Cache::flush();
    }

    public function test_bel_ongs_to_tenant_scope_isolates_data_between_tenants(): void
    {
        $tenantA = Tenant::factory()->create(['slug' => 'tenant-a', 'status' => 'active', 'trial_ends_at' => null]);
        $tenantB = Tenant::factory()->create(['slug' => 'tenant-b', 'status' => 'active', 'trial_ends_at' => null]);

        $branchA = Branch::factory()->forTenant($tenantA)->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        // Bind tenant A as current context — scope must filter to tenant A only
        app()->instance('currentTenant', $tenantA);

        $visible = Branch::all();
        $this->assertTrue($visible->contains('id', $branchA->id));
        $this->assertFalse($visible->contains('id', $branchB->id));
    }

    public function test_tenant_b_data_not_visible_when_tenant_a_is_current_context(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->forTenant($tenantA)->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        // Even if a user of tenant A somehow makes a request,
        // the global scope under tenant A context will NOT return tenant B branches
        app()->instance('currentTenant', $tenantA);

        // Direct model query filtered to current tenant
        $result = Branch::where('id', $branchB->id)->first();
        $this->assertNull($result, 'Tenant B branch must be invisible under tenant A context');
    }

    public function test_unauthenticated_request_to_tenant_route_returns_401(): void
    {
        config([
            'tenancy.resolver' => 'subdomain',
            'tenancy.base_domain' => 'eternova.app',
        ]);

        Tenant::factory()->create(['slug' => 'any-shop', 'status' => 'active', 'trial_ends_at' => null]);

        // Path must live under /api so the SPA catch-all (routes/web.php /{any?}
        // with ^(?!api)) doesn't shadow it and return the SPA shell (200) — which
        // made this isolation assertion a silent false negative.
        Route::middleware(['tenant', 'auth:sanctum'])
            ->get('/api/_test/protected', static fn () => response()->json(['ok' => true]))
            ->name('test.protected');

        $this->getJson('http://any-shop.eternova.app/api/_test/protected')
            ->assertStatus(401);
    }

    public function test_user_not_in_tenant_has_null_role_in_that_tenant_context(): void
    {
        $tenant = Tenant::factory()->create();

        // User with NO tenant memberships
        $userWithNoTenant = User::factory()->create();

        // Simulate the tenant being resolved (as if EnsureTenant ran)
        app()->instance('currentTenant', $tenant);

        // currentRole() must return null — user is not a member
        $this->assertNull($userWithNoTenant->currentRole());
    }

    public function test_me_endpoint_returns_empty_tenants_array_for_user_with_no_membership(): void
    {
        $user = User::factory()->create();

        // User exists but has no tenant memberships (fresh after registration)
        $response = $this->actingAs($user)->getJson('/api/v1/me');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.tenants'));
    }

    public function test_user_role_in_tenant_a_does_not_grant_role_in_tenant_b(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->forTenant($tenantA, role: 'owner')->create();

        // Set current context to tenant B
        app()->instance('currentTenant', $tenantB);

        // UserA has a role in tenant A, but NOT in tenant B
        $this->assertNull($userA->currentRole(), 'User role in tenant A must not transfer to tenant B');
        $this->assertFalse($userA->belongsToTenant($tenantB));
    }
}
