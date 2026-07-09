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
use Illuminate\Support\Facades\DB;
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
            'created_at' => now()->subDays(2),
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
        $okVariant = ProductVariant::factory()->forProduct($product)->create(['min_stock_alert' => 5]);
        $offVariant = ProductVariant::factory()->forProduct($product)->create(['min_stock_alert' => 0]);

        BranchInventory::factory()->forBranch($branch)->forVariant($lowVariant)->withStock(3)->create();  // 3 <= 5 → low
        BranchInventory::factory()->forBranch($branch)->forVariant($okVariant)->withStock(40)->create();  // not low
        BranchInventory::factory()->forBranch($branch)->forVariant($offVariant)->withStock(0)->create();  // threshold 0 → ignored

        $summary = $this->service->summary(14);

        $this->assertSame(1, $summary['kpis']['low_stock_count']);
    }

    public function test_low_stock_items_lists_variants_needing_restock_by_severity(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();

        $product = Product::factory()->forTenant($tenant)->create(['name' => 'Pulsera Plata']);

        $low = ProductVariant::factory()->forProduct($product)
            ->create(['min_stock_alert' => 5, 'options' => ['Talla' => 'S']]);
        $out = ProductVariant::factory()->forProduct($product)
            ->create(['min_stock_alert' => 5, 'options' => ['Talla' => 'M']]);
        $ok = ProductVariant::factory()->forProduct($product)->create(['min_stock_alert' => 5]);
        $off = ProductVariant::factory()->forProduct($product)->create(['min_stock_alert' => 0]);

        BranchInventory::factory()->forBranch($branch)->forVariant($low)->withStock(3)->create();  // low
        BranchInventory::factory()->forBranch($branch)->forVariant($out)->withStock(0)->create();  // out
        BranchInventory::factory()->forBranch($branch)->forVariant($ok)->withStock(40)->create();  // excluded
        BranchInventory::factory()->forBranch($branch)->forVariant($off)->withStock(0)->create();  // opted out

        $items = $this->service->summary(14)['low_stock_items'];

        $this->assertCount(2, $items);
        // Out of stock comes first, then the low one.
        $this->assertSame($out->id, $items[0]['variant_id']);
        $this->assertSame(0, $items[0]['available']);
        $this->assertSame('Talla: M', $items[0]['variant_label']);
        $this->assertSame($branch->name, $items[0]['branch_name']);
        $this->assertSame(5, $items[0]['min_stock_alert']);
        $this->assertSame($low->id, $items[1]['variant_id']);
    }

    public function test_low_stock_items_are_isolated_per_tenant(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create(['is_main' => true]);
        $productB = Product::factory()->forTenant($tenantB)->create();
        $variantB = ProductVariant::factory()->forProduct($productB)->create(['min_stock_alert' => 5]);
        BranchInventory::factory()->forBranch($branchB)->forVariant($variantB)->withStock(0)->create();

        app()->instance('currentTenant', $tenantA);
        $items = $this->service->summary(14)['low_stock_items'];

        $this->assertSame([], $items);
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

    /**
     * N+1 guard: the dashboard summary issues a bounded number of queries that
     * does NOT grow with the number of rows. We measure the same workload at two
     * dataset sizes; if the query count is identical, no relation is being lazily
     * loaded per row. Model::preventLazyLoading() (AppServiceProvider) is the
     * runtime backstop; this test pins the contract so a future change that
     * reintroduces an N+1 fails here.
     */
    public function test_summary_does_not_n_plus_one_with_row_count(): void
    {
        $countQueriesForOrders = function (int $orders): int {
            $tenant = Tenant::factory()->create();
            $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
            app()->instance('currentTenant', $tenant);

            $product = Product::factory()->forTenant($tenant)->create();
            $variant = ProductVariant::factory()->forProduct($product)->create();

            for ($i = 0; $i < $orders; $i++) {
                $order = Order::factory()->forTenant($tenant)->forBranch($branch)->create(['total_cents' => 1000]);
                OrderItem::factory()->forOrder($order)->forVariant($variant)->create(['quantity' => 1]);
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->service->summary(14);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $small = $countQueriesForOrders(3);
        $large = $countQueriesForOrders(15);

        $this->assertSame(
            $small,
            $large,
            "Dashboard summary query count grew from {$small} to {$large} as rows increased — an N+1 regression."
        );
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
