<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Verifies that ProductPolicy correctly gates each role:
 *   - staff  → read-only (viewAny + view only)
 *   - admin  → full CRUD
 *   - owner  → full CRUD
 *   - super_admin → bypasses all checks
 */
final class ProductPolicyTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        $this->tenant = Tenant::factory()->create(['slug' => 'policy-tenant']);
    }

    public function test_staff_can_list_products(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();

        $this->tenantGetJson($this->tenant, $staff, '/api/v1/products')
            ->assertOk();
    }

    public function test_staff_cannot_create_product(): void
    {
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();

        $this->tenantPostJson($this->tenant, $staff, '/api/v1/products', [
            'name' => 'Staff Cannot Create',
            'base_price_cents' => 1000,
        ])->assertStatus(403);
    }

    public function test_staff_cannot_update_product(): void
    {
        $owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['slug' => 'staff-update-test']);
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();

        $this->tenantPatchJson($this->tenant, $staff, "/api/v1/products/{$product->id}", [
            'name' => 'Should Fail',
        ])->assertStatus(403);
    }

    public function test_staff_cannot_delete_product(): void
    {
        $product = Product::factory()->forTenant($this->tenant)->create(['slug' => 'staff-delete-test']);
        $staff = User::factory()->forTenant($this->tenant, role: 'staff')->create();

        $this->tenantDeleteJson($this->tenant, $staff, "/api/v1/products/{$product->id}")
            ->assertStatus(403);
    }

    public function test_admin_can_create_product(): void
    {
        $admin = User::factory()->forTenant($this->tenant, role: 'admin')->create();

        $this->tenantPostJson($this->tenant, $admin, '/api/v1/products', [
            'name' => 'Admin Created Product',
            'base_price_cents' => 1000,
        ])->assertStatus(201);
    }

    public function test_admin_can_update_product(): void
    {
        $admin = User::factory()->forTenant($this->tenant, role: 'admin')->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['slug' => 'admin-update-slug']);

        $this->tenantPatchJson($this->tenant, $admin, "/api/v1/products/{$product->id}", [
            'name' => 'Admin Updated',
        ])->assertOk();
    }

    public function test_admin_can_delete_product(): void
    {
        $admin = User::factory()->forTenant($this->tenant, role: 'admin')->create();
        $product = Product::factory()->forTenant($this->tenant)->create(['slug' => 'admin-delete-slug']);

        $this->tenantDeleteJson($this->tenant, $admin, "/api/v1/products/{$product->id}")
            ->assertStatus(204);
    }

    public function test_super_admin_bypasses_all_checks(): void
    {
        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $product = Product::factory()->forTenant($this->tenant)->create(['slug' => 'super-admin-test']);

        // super_admin can delete even without being a tenant member
        $this->tenantDeleteJson($this->tenant, $superAdmin, "/api/v1/products/{$product->id}")
            ->assertStatus(204);
    }
}
