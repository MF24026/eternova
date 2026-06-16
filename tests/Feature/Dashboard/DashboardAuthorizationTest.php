<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\User;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Authorization for GET /api/v1/dashboard.
 *
 * The dashboard exposes tenant-wide figures (sales, expenses, pending orders and
 * recent orders WITH customer names). Before the 'dashboard.view' gate it had no
 * authorization at all — every other admin endpoint goes through a policy that
 * checks tenant membership, but this one only had auth:sanctum + tenant. That let
 * any authenticated user read ANY tenant's dashboard (cross-tenant leak) and let
 * the read-only 'customer' role read financials. These tests pin the gate shut.
 */
final class DashboardAuthorizationTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private function tenantWithBranch(): Tenant
    {
        $tenant = Tenant::factory()->create();
        Branch::factory()->forTenant($tenant)->create(['is_main' => true]);

        return $tenant;
    }

    private function dashboardUrl(Tenant $tenant): string
    {
        return $this->tenantUrl($tenant, 'api/v1/dashboard');
    }

    public function test_owner_can_view_dashboard(): void
    {
        $tenant = $this->tenantWithBranch();
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        $this->actingAs($owner)
            ->getJson($this->dashboardUrl($tenant))
            ->assertOk()
            ->assertJsonStructure(['data' => ['kpis']]);
    }

    public function test_staff_can_view_dashboard(): void
    {
        $tenant = $this->tenantWithBranch();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->actingAs($staff)
            ->getJson($this->dashboardUrl($tenant))
            ->assertOk();
    }

    public function test_customer_role_is_forbidden(): void
    {
        $tenant = $this->tenantWithBranch();
        $customer = User::factory()->forTenant($tenant, role: 'customer')->create();

        $this->actingAs($customer)
            ->getJson($this->dashboardUrl($tenant))
            ->assertStatus(403);
    }

    public function test_member_of_another_tenant_cannot_read_this_dashboard(): void
    {
        // The cross-tenant leak: an owner of tenant A, with a perfectly valid
        // session, must NOT be able to read tenant B's dashboard.
        $tenantA = $this->tenantWithBranch();
        $ownerA = User::factory()->forTenant($tenantA, role: 'owner')->create();

        $tenantB = $this->tenantWithBranch();

        $this->actingAs($ownerA)
            ->getJson($this->dashboardUrl($tenantB))
            ->assertStatus(403);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $tenant = $this->tenantWithBranch();

        $this->getJson($this->dashboardUrl($tenant))
            ->assertStatus(401);
    }

    public function test_super_admin_can_view_any_dashboard(): void
    {
        $tenant = $this->tenantWithBranch();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->getJson($this->dashboardUrl($tenant))
            ->assertOk();
    }
}
