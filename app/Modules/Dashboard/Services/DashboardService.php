<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Services;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Orders\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the tenant dashboard KPIs, sales series, top products and recent
 * activity. All figures are scoped to the current tenant.
 *
 * Single-table aggregations use the Eloquent models so the BelongsToTenant
 * global scope + SoftDeletes are applied automatically. Join-heavy queries use
 * the query builder with an explicit tenant_id filter (and whereNull deleted_at
 * where the joined table is soft-deletable).
 *
 * Money is always in centavos; the frontend formats it.
 */
final class DashboardService
{
    /** Order statuses that count as "active / not yet fulfilled". */
    private const PENDING_STATUSES = ['pending', 'preparing', 'ready', 'dispatched'];

    /**
     * @return array<string, mixed>
     */
    public function summary(int $rangeDays): array
    {
        $tenantId = current_tenant()->id;
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $seriesFrom = now()->subDays($rangeDays - 1)->startOfDay();

        return [
            'kpis' => [
                'today_sales_cents' => (int) Order::query()
                    ->whereDate('created_at', $today)
                    ->where('status', '!=', 'cancelled')
                    ->sum('total_cents'),

                'pending_orders' => Order::query()
                    ->whereIn('status', self::PENDING_STATUSES)
                    ->count(),

                'low_stock_count' => $this->lowStockCount($tenantId),

                'month_expenses_cents' => (int) Expense::query()
                    ->whereBetween('expense_date', [$monthStart, $monthEnd])
                    ->sum('amount_cents'),
            ],
            'sales_series' => $this->salesSeries($tenantId, $seriesFrom, $rangeDays),
            'top_products' => $this->topProducts($tenantId, $monthStart),
            'recent_orders' => $this->recentOrders(),
            'range_days' => $rangeDays,
        ];
    }

    /**
     * Count of (branch, variant) inventory rows at or below their variant's
     * low-stock alert threshold. Variants with min_stock_alert = 0 are ignored.
     */
    private function lowStockCount(string $tenantId): int
    {
        return DB::table('branch_inventory as bi')
            ->join('product_variants as pv', 'pv.id', '=', 'bi.product_variant_id')
            ->where('bi.tenant_id', $tenantId)
            ->where('pv.min_stock_alert', '>', 0)
            ->whereColumn('bi.available', '<=', 'pv.min_stock_alert')
            ->count();
    }

    /**
     * Daily sales totals over the range, with zero-filled gaps so the chart has
     * one point per day.
     *
     * @return list<array{date: string, total_cents: int}>
     */
    private function salesSeries(string $tenantId, Carbon $from, int $days): array
    {
        $byDate = DB::table('orders')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, SUM(total_cents) as t')
            ->groupBy('d')
            ->pluck('t', 'd');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $from->copy()->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'total_cents' => (int) ($byDate[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * Top 5 products by units sold this month (variants rolled up to the product).
     *
     * @return list<array{name: string, units: int}>
     */
    private function topProducts(string $tenantId, string $monthStart): array
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('product_variants as pv', 'pv.id', '=', 'oi.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->where('o.tenant_id', $tenantId)
            ->whereNull('o.deleted_at')
            ->where('o.status', '!=', 'cancelled')
            ->whereDate('o.created_at', '>=', $monthStart)
            ->groupBy('p.id', 'p.name')
            ->selectRaw('p.name as name, SUM(oi.quantity) as units')
            ->orderByDesc('units')
            ->limit(5)
            ->get()
            ->map(static fn (object $row): array => [
                'name' => (string) $row->name,
                'units' => (int) $row->units,
            ])
            ->all();
    }

    /**
     * The 5 most recent orders for the activity feed.
     *
     * @return list<array<string, mixed>>
     */
    private function recentOrders(): array
    {
        return Order::query()
            ->with('customer:id,name')
            ->latest()
            ->limit(5)
            ->get(['id', 'order_number', 'customer_id', 'total_cents', 'status', 'created_at'])
            ->map(static fn (Order $order): array => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'customer_name' => $order->customer?->name,
                'total_cents' => (int) $order->total_cents,
                'status' => $order->status,
                'created_at' => $order->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
