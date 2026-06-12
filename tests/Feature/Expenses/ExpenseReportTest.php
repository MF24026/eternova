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
 * Feature tests for the monthly expense report (S6-E5).
 *
 * GET /api/v1/expenses/report — totals grouped by category for a period, with
 * every active category zero-filled and uncategorised expenses bucketed.
 */
final class ExpenseReportTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

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

    public function test_unauthenticated_request_returns_401(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->tenantUrl($tenant, 'api/v1/expenses/report'))
            ->assertStatus(401);
    }

    public function test_report_aggregates_totals_by_category_for_a_month(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        $ops = ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Operacion', 'type' => 'operating']);
        $rent = ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Renta', 'type' => 'rent']);

        Expense::factory()->forBranch($branch)->forCategory($ops)->create(['amount_cents' => 10000, 'expense_date' => '2026-03-05']);
        Expense::factory()->forBranch($branch)->forCategory($ops)->create(['amount_cents' => 5000, 'expense_date' => '2026-03-20']);
        Expense::factory()->forBranch($branch)->forCategory($rent)->create(['amount_cents' => 80000, 'expense_date' => '2026-03-01']);
        // Different month — must be excluded.
        Expense::factory()->forBranch($branch)->forCategory($ops)->create(['amount_cents' => 99999, 'expense_date' => '2026-02-15']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/report?month=2026-03')
            ->assertOk();

        $response->assertJsonPath('data.total_cents', 95000); // 10000 + 5000 + 80000
        $byCategory = collect($response->json('data.by_category'));

        $opsRow = $byCategory->firstWhere('category_id', $ops->id);
        $this->assertSame(15000, $opsRow['total_cents']);
        $this->assertSame(2, $opsRow['count']);

        $rentRow = $byCategory->firstWhere('category_id', $rent->id);
        $this->assertSame(80000, $rentRow['total_cents']);
        $this->assertSame(1, $rentRow['count']);
    }

    public function test_active_categories_with_no_expenses_are_zero_filled(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        $used = ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Usada']);
        $empty = ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Vacia']);

        Expense::factory()->forBranch($branch)->forCategory($used)->create(['amount_cents' => 3000, 'expense_date' => '2026-03-10']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/report?month=2026-03')
            ->assertOk();

        $emptyRow = collect($response->json('data.by_category'))->firstWhere('category_id', $empty->id);
        $this->assertNotNull($emptyRow);
        $this->assertSame(0, $emptyRow['total_cents']);
        $this->assertSame(0, $emptyRow['count']);
    }

    public function test_verified_only_filter_excludes_drafts(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $cat = ExpenseCategory::factory()->forTenant($tenant)->create();

        Expense::factory()->forBranch($branch)->forCategory($cat)->create(['amount_cents' => 4000, 'expense_date' => '2026-03-10', 'is_verified' => true]);
        Expense::factory()->forBranch($branch)->forCategory($cat)->create(['amount_cents' => 1000, 'expense_date' => '2026-03-11', 'is_verified' => false]);

        $all = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/report?month=2026-03')->assertOk();
        $this->assertSame(5000, $all->json('data.total_cents'));

        $verified = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/report?month=2026-03&verified_only=true')->assertOk();
        $this->assertSame(4000, $verified->json('data.total_cents'));
    }

    public function test_uncategorised_expenses_are_bucketed(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        Expense::factory()->forBranch($branch)->create(['expense_category_id' => null, 'amount_cents' => 2500, 'expense_date' => '2026-03-09']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/report?month=2026-03')->assertOk();

        $uncat = collect($response->json('data.by_category'))->firstWhere('category_id', null);
        $this->assertNotNull($uncat);
        $this->assertSame('Sin categoria', $uncat['category_name']);
        $this->assertSame(2500, $uncat['total_cents']);
    }

    public function test_inactive_category_with_expenses_still_appears(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        $retired = ExpenseCategory::factory()->forTenant($tenant)->create(['name' => 'Retirada', 'is_active' => false]);
        Expense::factory()->forBranch($branch)->forCategory($retired)->create(['amount_cents' => 7000, 'expense_date' => '2026-03-12']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses/report?month=2026-03')->assertOk();

        $row = collect($response->json('data.by_category'))->firstWhere('category_id', $retired->id);
        $this->assertNotNull($row, 'A retired category with expenses must still appear in the report.');
        $this->assertSame(7000, $row['total_cents']);
        $this->assertSame(7000, $response->json('data.total_cents'));
    }

    public function test_report_is_tenant_scoped(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA, 'owner' => $ownerA] = $this->setupTenant();
        $catA = ExpenseCategory::factory()->forTenant($tenantA)->create();
        Expense::factory()->forBranch($branchA)->forCategory($catA)->create(['amount_cents' => 6000, 'expense_date' => '2026-03-15']);

        ['tenant' => $tenantB, 'branch' => $branchB] = $this->setupTenant();
        $catB = ExpenseCategory::factory()->forTenant($tenantB)->create();
        Expense::factory()->forBranch($branchB)->forCategory($catB)->create(['amount_cents' => 50000, 'expense_date' => '2026-03-15']);

        // Owner A sees only tenant A's total.
        app()->instance('currentTenant', $tenantA);
        $response = $this->tenantGetJson($tenantA, $ownerA, '/api/v1/expenses/report?month=2026-03')->assertOk();
        $this->assertSame(6000, $response->json('data.total_cents'));
    }
}
