<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the ExpenseCategory CRUD API (S6-E4).
 *
 * Verifies:
 *   - index returns all categories for the current tenant (active + inactive)
 *   - index is scoped per tenant (cross-tenant categories not visible)
 *   - store creates a category for the current tenant; unique-name enforced per tenant
 *   - update renames, changes type, or toggles is_active; no-op rename is valid
 *   - destroy deletes an unused category; blocked with 422 when in use
 *   - auth gates: 401 unauthenticated, 403 staff cannot manage categories
 */
final class ExpenseCategoryApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    // ── Auth gates ────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->tenantUrl($tenant, 'api/v1/expenses/categories'))
            ->assertStatus(401);
    }

    public function test_staff_can_list_categories(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->tenantGetJson($tenant, $staff, '/api/v1/expenses/categories')
            ->assertOk();
    }

    public function test_staff_cannot_create_category(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->tenantPostJson($tenant, $staff, '/api/v1/expenses/categories', [
            'name' => 'Test',
            'type' => 'other',
        ])->assertStatus(403);
    }

    public function test_staff_cannot_update_category(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $this->tenantPatchJson($tenant, $staff, "/api/v1/expenses/categories/{$category->id}", [
            'name' => 'Renamed',
        ])->assertStatus(403);
    }

    public function test_staff_cannot_delete_category(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $this->tenantDeleteJson($tenant, $staff, "/api/v1/expenses/categories/{$category->id}")
            ->assertStatus(403);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_returns_all_categories_for_current_tenant(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        ExpenseCategory::factory()->forTenant($tenant)->count(3)->create();
        ExpenseCategory::factory()->forTenant($tenant)->inactive()->create();

        // Tenant B's categories — must not appear
        ['tenant' => $tenantB] = $this->setupTenant();
        ExpenseCategory::factory()->forTenant($tenantB)->count(5)->create();

        app()->instance('currentTenant', $tenant);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/categories')
            ->assertOk()
            ->assertJsonStructure(['data']);

        // 3 active + 1 inactive = 4 for tenant A
        $this->assertCount(4, $response->json('data'));
    }

    public function test_index_returns_categories_ordered_by_name(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Zemsa']);
        ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Alpha']);
        ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Mango']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/categories')
            ->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['Alpha', 'Mango', 'Zemsa'], $names);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_category_for_current_tenant(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantPostJson($tenant, $owner, '/api/v1/expenses/categories', [
            'name' => 'Servicios de Mensajeria',
            'type' => 'operating',
        ])->assertStatus(201)
            ->assertJsonPath('data.name', 'Servicios de Mensajeria')
            ->assertJsonPath('data.type', 'operating')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('expense_categories', [
            'tenant_id' => $tenant->id,
            'name' => 'Servicios de Mensajeria',
            'type' => 'operating',
        ]);
    }

    public function test_store_enforces_unique_name_per_tenant(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Operaciones']);

        $this->tenantPostJson($tenant, $owner, '/api/v1/expenses/categories', [
            'name' => 'Operaciones',
            'type' => 'operating',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_store_allows_same_name_across_different_tenants(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB] = $this->setupTenant();

        // Create the category for tenant B first
        app()->instance('currentTenant', $tenantB);
        ExpenseCategory::factory()->forTenant($tenantB)->create(['name' => 'Alquiler Local']);

        // Tenant A should be able to create the same name
        app()->instance('currentTenant', $tenantA);

        $this->tenantPostJson($tenantA, $ownerA, '/api/v1/expenses/categories', [
            'name' => 'Alquiler Local',
            'type' => 'rent',
        ])->assertStatus(201);
    }

    public function test_store_requires_name_and_type(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantPostJson($tenant, $owner, '/api/v1/expenses/categories', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'type']);
    }

    public function test_store_type_must_be_a_valid_value(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantPostJson($tenant, $owner, '/api/v1/expenses/categories', [
            'name' => 'Test',
            'type' => 'invalid_type',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_renames_a_category(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Operacion Vieja']);

        $this->tenantPatchJson($tenant, $owner, "/api/v1/expenses/categories/{$category->id}", [
            'name' => 'Operacion Nueva',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Operacion Nueva');
    }

    public function test_update_no_op_rename_keeps_same_name_without_unique_violation(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Renta']);

        // Send the same name — should not fail uniqueness
        $this->tenantPatchJson($tenant, $owner, "/api/v1/expenses/categories/{$category->id}", [
            'name' => 'Renta',
        ])->assertOk();
    }

    public function test_update_can_retire_a_category_by_setting_is_active_false(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create(['is_active' => true]);

        $this->tenantPatchJson($tenant, $owner, "/api/v1/expenses/categories/{$category->id}", [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('expense_categories', [
            'id' => $category->id,
            'is_active' => false,
        ]);
    }

    public function test_update_cross_tenant_category_returns_404(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB] = $this->setupTenant();

        app()->instance('currentTenant', $tenantB);
        $catB = ExpenseCategory::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantA);

        $this->tenantPatchJson($tenantA, $ownerA, "/api/v1/expenses/categories/{$catB->id}", [
            'name' => 'Hijacked',
        ])->assertStatus(404);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_deletes_an_unused_category(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $this->tenantDeleteJson($tenant, $owner, "/api/v1/expenses/categories/{$category->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('expense_categories', ['id' => $category->id]);
    }

    public function test_destroy_is_blocked_when_category_has_associated_expenses(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create();
        Expense::factory()->forBranch($branch)->forCategory($category)->create();

        $this->tenantDeleteJson($tenant, $owner, "/api/v1/expenses/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'expenses.category_in_use');

        // Category must still exist
        $this->assertDatabaseHas('expense_categories', ['id' => $category->id]);
    }

    public function test_destroy_is_blocked_when_category_has_soft_deleted_expenses(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $expense = Expense::factory()->forBranch($branch)->forCategory($category)->create();
        $expense->delete(); // soft-delete

        $this->tenantDeleteJson($tenant, $owner, "/api/v1/expenses/categories/{$category->id}")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'expenses.category_in_use');
    }

    public function test_destroy_cross_tenant_category_returns_404(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB] = $this->setupTenant();

        app()->instance('currentTenant', $tenantB);
        $catB = ExpenseCategory::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantA);

        $this->tenantDeleteJson($tenantA, $ownerA, "/api/v1/expenses/categories/{$catB->id}")
            ->assertStatus(404);
    }
}
