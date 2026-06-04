<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Resolve a tenant as the "current tenant" in the container — simulates what
     * EnsureTenant middleware does during an HTTP request.
     */
    private function setTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    /**
     * Clear the tenant context — simulates a CLI / super-admin context where no
     * tenant is resolved.
     */
    private function clearTenant(): void
    {
        // Re-bind the default null resolver registered in TenancyServiceProvider.
        app()->bind('currentTenant', fn (): ?Tenant => null);
    }

    public function test_belongs_to_tenant_trait_filters_queries_automatically(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        // Create branches for each tenant by providing tenant_id explicitly to bypass the
        // global scope check (no tenant is resolved in the container yet at this point).
        $branchA1 = Branch::factory()->forTenant($tenantA)->create();
        $branchA2 = Branch::factory()->forTenant($tenantA)->create();
        $branchB1 = Branch::factory()->forTenant($tenantB)->create();

        // Resolve tenant A — the global scope should now filter to tenant A only.
        $this->setTenant($tenantA);

        $branches = Branch::all();

        $this->assertCount(2, $branches);
        $this->assertTrue($branches->contains('id', $branchA1->id));
        $this->assertTrue($branches->contains('id', $branchA2->id));
        $this->assertFalse($branches->contains('id', $branchB1->id));
    }

    public function test_creating_model_without_tenant_throws_exception_outside_tenant_context(): void
    {
        // Ensure no tenant is resolved in the container.
        $this->clearTenant();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/requires a resolved tenant/');

        // Attempting to create a Branch without resolving a tenant and without providing
        // an explicit tenant_id must throw — not silently assign null or tenant 1.
        Branch::create([
            'name' => 'Sucursal Sin Tenant',
            'slug' => 'sin-tenant',
        ]);
    }

    public function test_for_tenant_scope_bypasses_global_scope(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $branchA = Branch::factory()->forTenant($tenantA)->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        // Resolve tenant A — normal queries return only tenant A's data.
        $this->setTenant($tenantA);

        // Using forTenant($tenantB->id) must bypass the global scope and return tenant B's branch.
        $result = Branch::forTenant($tenantB->id)->get();

        $this->assertCount(1, $result);
        $this->assertTrue($result->contains('id', $branchB->id));
        $this->assertFalse($result->contains('id', $branchA->id));
    }

    public function test_auto_sets_tenant_id_on_create_when_tenant_is_resolved(): void
    {
        $tenant = Tenant::factory()->create();
        $this->setTenant($tenant);

        $branch = Branch::create([
            'name' => 'Sucursal Auto',
            'slug' => 'sucursal-auto',
        ]);

        $this->assertSame($tenant->id, $branch->tenant_id);
    }

    public function test_explicit_tenant_id_is_respected_and_not_overridden(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        // Resolve tenant A in the container.
        $this->setTenant($tenantA);

        // Explicitly provide tenant B's id — should override the auto-set from context.
        // This is used by super-admin and seeder code.
        $branch = Branch::create([
            'tenant_id' => $tenantB->id,
            'name' => 'Sucursal Explícita',
            'slug' => 'sucursal-explicita',
        ]);

        $this->assertSame($tenantB->id, $branch->tenant_id);
    }

    public function test_no_tenant_context_shows_all_branches_unfiltered(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        Branch::factory()->forTenant($tenantA)->count(2)->create();
        Branch::factory()->forTenant($tenantB)->count(3)->create();

        // No tenant resolved — the global scope must not filter.
        $this->clearTenant();

        $all = Branch::all();

        $this->assertCount(5, $all);
    }
}
