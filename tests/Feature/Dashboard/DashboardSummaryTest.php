<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Dashboard\Services\DashboardService;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the dashboard aggregations (S9-E1).
 *
 * Verifies KPI math (today sales excludes cancelled, pending count, low stock,
 * month expenses), the zero-filled sales series length, top products, and
 * multi-tenant isolation.
 */
final class DashboardSummaryTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardService::class);
    }

    /**
     * @return array{tenant: Tenant, branch: Branch}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch');
    }

    public function test_today_sales_sums_todays_non_cancelled_orders_only(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();

        Order::factory()->forTenant($tenant)->forBranch($branch)->create(['total_cents' => 10000]); // today, counts
        Order::factory()->forTenant($tenant)->forBranch($branch)->cancelled()->create(['total_cents' => 5000]); // excluded
        Order::factory()->forTenant($tenant)->forBranch($branch)->create([
            'total_cents' => 8000,
            'created_at'  => now()->subDays(2),
        ]); // not today

        $summary = $this->service->summary(14);

        $this->assertSame(10000, $summary['kpis']['today_sales_cents']);
    }

    public function test_pending_orders_counts_active_statuses_only(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();

        Order::factory()->forTenant($tenant)->forBranch($branch)->create(['status' => 'pending']);
        Order::factory()->forTenant($tenant)->forBranch($branch)->create(['status' => 'preparing']);
        Order::factory()->forTenant($tenant)->forBranch($branch)->create(['status' => 'ready']);
        Order::factory()->forTenant($tenant)->forBranch($branch)->create(['status' => 'delivered']); // excluded
        Order::factory()->forTenant($tenant)->forBranch($branch)->cancelled()->create();             // excluded

        $summary = $this->service->summary(14);

        $this->assertSame(3, $summary['kpis']['pending_orders']);
    }

    public function test_low_stock_counts_inventory_below_variant_threshold(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();

        $product = Product::factory()->forTenant($tenant)->create();

        $lowVariant = ProductVariant::factory()->forProduct($product)->create(['min_stock_alert' => 5]);
        $okVariant  = ProductVariant::factory()->forProduct($product)->create(['min_stock_alert' => 5]);
        $offVariant = ProductVariant::factory()->forProduct($product)->create(['min_stock_alert' => 0]);

        BranchInventory::factory()->forBranch($branch)->forVariant($lowVariant)->withStock(3)->create();  // 3 <= 5 → low
        BranchInventory::factory()->forBranch($branch)->forVariant($okVariant)->withStock(40)->create();  // not low
        BranchInventory::factory()->forBranch($branch)->forVariant($offVariant)->withStock(0)->create();  // threshold 0 → ignored

        $summary = $this->service->summary(14);

        $this->assertSame(1, $summary['kpis']['low_stock_count']);
    }

    public function test_month_expenses_sums_current_month_only(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        Expense::factory()->forTenant($tenant)->create([
            'amount_cents' => 20000,
            'expense_date' => now()->startOfMonth()->toDateString(),
        ]);
        Expense::factory()->forTenant($tenant)->create([
            'amount_cents' => 9999,
            'expense_date' => now()->startOfMonth()->subMonth()->toDateString(),
        ]); // last month, excluded

        $summary = $this->service->summary(14);

        $this->assertSame(20000, $summary['kpis']['month_expenses_cents']);
    }

    public function test_sales_series_has_one_zero_filled_point_per_day(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();

        Order::factory()->forTenant($tenant)->forBranch($branch)->create(['total_cents' => 4200]);

        $summary = $this->service->summary(7);

        $this->assertCount(7, $summary['sales_series']);
        // Last point is today and includes the order total.
        $last = end($summary['sales_series']);
        $this->assertSame(now()->toDateString(), $last['date']);
        $this->assertSame(4200, $last['total_cents']);
    }

    public function test_top_products_ranks_by_units_sold(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();

        $product = Product::factory()->forTenant($tenant)->create(['name' => 'Rosa Eterna']);
        $variant = ProductVariant::factory()->forProduct($product)->create();

        $order = Order::factory()->forTenant($tenant)->forBranch($branch)->create();
        OrderItem::factory()->forOrder($order)->forVariant($variant)->create(['quantity' => 7]);

        $summary = $this->service->summary(14);

        $this->assertNotEmpty($summary['top_products']);
        $this->assertSame('Rosa Eterna', $summary['top_products'][0]['name']);
        $this->assertSame(7, $summary['top_products'][0]['units']);
    }

    public function test_kpis_are_isolated_per_tenant(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();
        Order::factory()->forTenant($tenantA)->forBranch($branchA)->create(['total_cents' => 10000]);

        // Tenant B has a big sale today that must NOT leak into A's figures.
        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create(['is_main' => true]);
        Order::factory()->forTenant($tenantB)->forBranch($branchB)->create(['total_cents' => 99999]);

        // Resolve for tenant A.
        app()->instance('currentTenant', $tenantA);
        $summary = $this->service->summary(14);

        $this->assertSame(10000, $summary['kpis']['today_sales_cents']);
    }
}
