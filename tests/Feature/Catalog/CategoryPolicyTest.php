<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Policies\CategoryPolicy;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CategoryPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private CategoryPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->policy = new CategoryPolicy;

        app()->instance('currentTenant', $this->tenant);
    }

    // ── viewAny ──────────────────────────────────────────────────────────────

    public function test_owner_can_view_any(): void
    {
        $owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
        $this->assertTrue($this->policy->viewAny($owner));
    }

    public function test_admin_can_view_any(): void
    {
        $admin = User::factory()->forTenant($this->tenant, role: 'admin')->create();
        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_staff_can_view_any(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();
        $this->assertTrue($this->policy->viewAny($staff));
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function test_owner_can_create(): void
    {
        $owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
        $this->assertTrue($this->policy->create($owner));
    }

    public function test_admin_can_create(): void
    {
        $admin = User::factory()->forTenant($this->tenant, role: 'admin')->create();
        $this->assertTrue($this->policy->create($admin));
    }

    public function test_staff_cannot_create(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();
        $this->assertFalse($this->policy->create($staff));
    }

    // ── update ───────────────────────────────────────────────────────────────

    public function test_owner_can_update_own_tenant_category(): void
    {
        $owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'upd-own']);

        $this->assertTrue($this->policy->update($owner, $category));
    }

    public function test_staff_cannot_update_category(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'upd-staff']);

        $this->assertFalse($this->policy->update($staff, $category));
    }

    public function test_owner_cannot_update_category_of_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        $owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
        $categoryB = Category::factory()->forTenant($tenantB)->create();

        // current tenant is tenantA, categoryB belongs to tenantB
        $this->assertFalse($this->policy->update($owner, $categoryB));
    }

    // ── delete ───────────────────────────────────────────────────────────────

    public function test_admin_can_delete_own_tenant_category(): void
    {
        $admin = User::factory()->forTenant($this->tenant, role: 'admin')->create();
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'del-admin']);

        $this->assertTrue($this->policy->delete($admin, $category));
    }

    public function test_staff_cannot_delete_category(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'del-staff']);

        $this->assertFalse($this->policy->delete($staff, $category));
    }

    // ── restore ──────────────────────────────────────────────────────────────

    public function test_owner_can_restore_own_tenant_category(): void
    {
        $owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'res-own']);
        $category->delete();

        $this->assertTrue($this->policy->restore($owner, $category));
    }

    public function test_staff_cannot_restore_category(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();
        $category = Category::factory()->forTenant($this->tenant)->create(['slug' => 'res-staff']);

        $this->assertFalse($this->policy->restore($staff, $category));
    }

    // ── reorder ──────────────────────────────────────────────────────────────

    public function test_owner_can_reorder(): void
    {
        $owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
        $this->assertTrue($this->policy->reorder($owner));
    }

    public function test_staff_cannot_reorder(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();
        $this->assertFalse($this->policy->reorder($staff));
    }

    // ── super_admin bypass ───────────────────────────────────────────────────

    public function test_super_admin_bypasses_all_checks_via_before(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->assertTrue($this->policy->before($superAdmin, 'create'));
        $this->assertTrue($this->policy->before($superAdmin, 'delete'));
    }
}
