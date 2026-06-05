<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for TenantScopedPolicy helper methods.
 *
 * We test a concrete anonymous subclass rather than the abstract class directly,
 * using public proxy methods to call the protected helpers.
 */
final class PolicyTest extends TestCase
{
    use RefreshDatabase;

    private function makePolicy(): TenantScopedPolicy
    {
        // Anonymous concrete class to expose the protected methods for testing
        return new class extends TenantScopedPolicy
        {
            public function checkTenantMatches(User $user, Model $resource): bool
            {
                return $this->assertTenantMatches($user, $resource);
            }

            public function checkHasRole(User $user, string|array $allowedRoles): bool
            {
                return $this->assertHasRole($user, $allowedRoles);
            }
        };
    }

    private function setCurrentTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);
    }

    public function test_owner_role_passes_assert_has_role_owner(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        $this->setCurrentTenant($tenant);

        $policy = $this->makePolicy();
        $this->assertTrue($policy->checkHasRole($owner, 'owner'));
        $this->assertTrue($policy->checkHasRole($owner, ['owner', 'admin']));
    }

    public function test_staff_role_fails_assert_has_role_owner_or_admin(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->setCurrentTenant($tenant);

        $policy = $this->makePolicy();
        $this->assertFalse($policy->checkHasRole($staff, 'owner'));
        $this->assertFalse($policy->checkHasRole($staff, ['owner', 'admin']));
    }

    public function test_staff_role_passes_assert_has_role_staff(): void
    {
        $tenant = Tenant::factory()->create();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->setCurrentTenant($tenant);

        $policy = $this->makePolicy();
        $this->assertTrue($policy->checkHasRole($staff, 'staff'));
        $this->assertTrue($policy->checkHasRole($staff, ['staff', 'admin']));
    }

    public function test_assert_has_role_returns_false_when_no_tenant_context(): void
    {
        // Clear tenant context — simulate CLI or platform route
        app()->bind('currentTenant', fn () => null);

        $user = User::factory()->create();
        $policy = $this->makePolicy();

        $this->assertFalse($policy->checkHasRole($user, 'owner'));
    }

    public function test_assert_tenant_matches_rejects_user_from_other_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $userA = User::factory()->forTenant($tenantA, role: 'owner')->create();

        // Set current tenant to A — userA belongs to A
        $this->setCurrentTenant($tenantA);

        // Create a branch belonging to tenant B
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        $policy = $this->makePolicy();

        // branchB.tenant_id === tenantB.id, but current tenant is A → must be false
        $this->assertFalse($policy->checkTenantMatches($userA, $branchB));
    }

    public function test_assert_tenant_matches_accepts_user_with_correct_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        $this->setCurrentTenant($tenant);

        // Create branch in the same tenant (bypass global scope by setting tenant context first)
        $branch = Branch::create([
            'tenant_id' => $tenant->id,
            'name' => 'Main Branch',
            'slug' => 'main',
            'is_main' => true,
            'is_active' => true,
        ]);

        $policy = $this->makePolicy();
        $this->assertTrue($policy->checkTenantMatches($owner, $branch));
    }

    public function test_super_admin_bypasses_all_checks_via_before(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $policy = $this->makePolicy();

        // before() returns true for super_admin, bypassing specific ability methods
        $result = $policy->before($superAdmin, 'viewAny');
        $this->assertTrue($result);
    }

    public function test_non_super_admin_before_returns_null_to_defer(): void
    {
        $regularUser = User::factory()->create();
        $policy = $this->makePolicy();

        // before() returns null for regular users — defers to specific ability method
        $this->assertNull($policy->before($regularUser, 'viewAny'));
    }
}
