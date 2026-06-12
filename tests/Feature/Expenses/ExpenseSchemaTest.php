<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\Expenses\ExpenseCategoriesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schema + model retrofit tests for Expenses (S6-E1).
 *
 * Verifies the retrofitted schema fixes the legacy bug (missing tenant_id), that
 * BelongsToTenant scoping works for both tables, relations resolve, money is stored
 * in centavos, and the default category seeder is idempotent.
 */
final class ExpenseSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner  = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    public function test_expense_and_category_can_be_created_with_all_fields(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        $category = ExpenseCategory::factory()->forTenant($tenant)->type('operating')->create([
            'name'      => 'Operación',
            'is_active' => true,
        ]);

        $expense = Expense::factory()
            ->forBranch($branch)
            ->forCategory($category)
            ->createdBy($owner)
            ->create([
                'description'  => 'Compra de materiales',
                'amount_cents' => 25000,
                'expense_date' => '2026-06-10',
                'vendor'       => 'Proveedor SA',
                'payment_method' => 'transfer',
                'is_verified'  => true,
                'ocr_status'   => 'none',
            ]);

        $this->assertDatabaseHas('expenses', [
            'id'                  => $expense->id,
            'tenant_id'           => $tenant->id,
            'branch_id'           => $branch->id,
            'expense_category_id' => $category->id,
            'description'         => 'Compra de materiales',
            'amount_cents'        => 25000,
            'expense_date'        => '2026-06-10',
            'vendor'              => 'Proveedor SA',
            'payment_method'      => 'transfer',
            'is_verified'         => true,
            'ocr_status'          => 'none',
            'created_by'          => $owner->id,
        ]);

        $this->assertDatabaseHas('expense_categories', [
            'id'        => $category->id,
            'tenant_id' => $tenant->id,
            'name'      => 'Operación',
            'type'      => 'operating',
            'is_active' => true,
        ]);
    }

    public function test_belongs_to_tenant_scope_filters_expenses(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();
        Expense::factory()->forBranch($branchA)->count(2)->create();

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();
        app()->instance('currentTenant', $tenantB);
        Expense::factory()->forBranch($branchB)->create();

        // Current tenant is B → only B's expense is visible.
        $this->assertSame(1, Expense::count());

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(2, Expense::count());
    }

    public function test_belongs_to_tenant_scope_filters_categories(): void
    {
        ['tenant' => $tenantA] = $this->setupTenant();
        ExpenseCategory::factory()->forTenant($tenantA)->count(3)->create();

        $tenantB = Tenant::factory()->create();
        app()->instance('currentTenant', $tenantB);
        ExpenseCategory::factory()->forTenant($tenantB)->count(2)->create();

        // Current tenant is B → only B's 2 categories visible.
        $this->assertSame(2, ExpenseCategory::count());

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(3, ExpenseCategory::count());
    }

    public function test_relations_resolve(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $expense = Expense::factory()
            ->forBranch($branch)
            ->forCategory($category)
            ->createdBy($owner)
            ->create();

        $expense->refresh();

        $this->assertTrue($expense->tenant->is($tenant));
        $this->assertTrue($expense->branch->is($branch));
        $this->assertTrue($expense->category->is($category));
        $this->assertTrue($expense->creator->is($owner));
    }

    public function test_expense_category_has_many_expenses(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $category = ExpenseCategory::factory()->forTenant($tenant)->create();
        Expense::factory()->forTenant($tenant)->forCategory($category)->count(3)->create();

        $category->refresh();

        $this->assertCount(3, $category->expenses);
    }

    public function test_money_column_is_integer_centavos(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $expense = Expense::factory()->forTenant($tenant)->create(['amount_cents' => 12345]);

        $this->assertIsInt($expense->amount_cents);
        $this->assertSame(12345, $expense->amount_cents);
    }

    public function test_expense_category_unique_name_per_tenant_is_enforced(): void
    {
        ['tenant' => $tenantA] = $this->setupTenant();

        ExpenseCategory::factory()->forTenant($tenantA)->create(['name' => 'Renta']);

        // Same name in a different tenant must succeed — uniqueness is per-tenant.
        $tenantB = Tenant::factory()->create();
        app()->instance('currentTenant', $tenantB);
        $catB = ExpenseCategory::factory()->forTenant($tenantB)->create(['name' => 'Renta']);
        $this->assertDatabaseHas('expense_categories', ['tenant_id' => $tenantB->id, 'name' => 'Renta']);

        // Duplicate name within the same tenant must fail with a DB integrity violation.
        $this->expectException(\Illuminate\Database\QueryException::class);
        app()->instance('currentTenant', $tenantA);
        ExpenseCategory::factory()->forTenant($tenantA)->create(['name' => 'Renta']);
    }

    public function test_expense_categories_seeder_creates_five_defaults_per_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        // Bind a tenant so any accidental Eloquent create() doesn't explode.
        app()->instance('currentTenant', $tenant);

        $this->artisan('db:seed', ['--class' => ExpenseCategoriesSeeder::class]);

        $categories = \Illuminate\Support\Facades\DB::table('expense_categories')
            ->where('tenant_id', $tenant->id)
            ->get();

        $this->assertCount(5, $categories);

        $names = $categories->pluck('name')->all();
        $this->assertContains('Operación', $names);
        $this->assertContains('Productos', $names);
        $this->assertContains('Nómina', $names);
        $this->assertContains('Renta', $names);
        $this->assertContains('Otros', $names);
    }

    public function test_expense_categories_seeder_is_idempotent(): void
    {
        $tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $tenant);

        // Run twice — second run must not duplicate rows.
        $this->artisan('db:seed', ['--class' => ExpenseCategoriesSeeder::class]);
        $this->artisan('db:seed', ['--class' => ExpenseCategoriesSeeder::class]);

        $count = \Illuminate\Support\Facades\DB::table('expense_categories')
            ->where('tenant_id', $tenant->id)
            ->count();

        $this->assertSame(5, $count);
    }

    public function test_is_draft_reflects_is_verified(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        // factory create() does not hydrate DB-applied defaults — call fresh() first.
        $verified = Expense::factory()->forTenant($tenant)->verified()->create()->fresh();
        $draft    = Expense::factory()->forTenant($tenant)->draft()->create()->fresh();

        $this->assertFalse($verified->isDraft());
        $this->assertTrue($draft->isDraft());
    }

    public function test_soft_deleted_expense_is_not_visible_but_exists_in_db(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $expense = Expense::factory()->forTenant($tenant)->create();
        $expense->delete();

        $this->assertSame(0, Expense::count());
        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    }

    public function test_expense_category_tenant_relation_resolves(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $this->assertTrue($category->tenant->is($tenant));
    }
}
