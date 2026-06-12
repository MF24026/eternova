<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Database\Seeders\Catalog\CategoriesSeeder;
use Database\Seeders\Catalog\ProductsSeeder;
use Database\Seeders\Catalog\TagsSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoTenantsSeeder;
use Database\Seeders\Inventory\BranchInventorySeeder;
use Database\Seeders\Orders\OrdersSeeder;
use Database\Seeders\PlansSeeder;
use Database\Seeders\Expenses\ExpenseCategoriesSeeder;
use Database\Seeders\Expenses\ExpensesSeeder;
use Database\Seeders\Reservations\ReservationsSeeder;
use Database\Seeders\ReservedSubdomainsSeeder;
use Database\Seeders\SuperAdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Integrity tests for the demo data seeders.
 *
 * All tests use RefreshDatabase so each test starts from a clean schema.
 * No mocks — every assertion hits the real DB.
 */
final class DemoDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ SuperAdmin

    public function test_super_admin_seeder_creates_one_super_admin(): void
    {
        $this->seed(SuperAdminUserSeeder::class);

        $this->assertDatabaseCount('users', 1);

        $admin = User::where('is_super_admin', true)->first();
        $this->assertNotNull($admin);
        $this->assertSame('admin@eternova.app', $admin->email);
        $this->assertTrue($admin->is_super_admin);
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_super_admin_seeder_is_idempotent(): void
    {
        $this->seed(SuperAdminUserSeeder::class);
        $this->seed(SuperAdminUserSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(1, User::where('is_super_admin', true)->count());
    }

    // ------------------------------------------------------------------ DemoTenants

    public function test_demo_tenants_seeder_creates_two_tenants_with_correct_owners(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        // Residual "demo" tenant removed in #28; total is now exactly 2.
        $this->assertSame(2, Tenant::count());

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $this->assertNotNull($rosaEterna);

        $tatiana = Tenant::findBySlug('tatiana');
        $this->assertNotNull($tatiana);

        // Each tenant has exactly one owner
        $rosaOwners = $rosaEterna->users()->wherePivot('role', 'owner')->count();
        $this->assertSame(1, $rosaOwners, 'rosa-eterna should have exactly 1 owner');

        $tatianaOwners = $tatiana->users()->wherePivot('role', 'owner')->count();
        $this->assertSame(1, $tatianaOwners, 'tatiana should have exactly 1 owner');

        // Verify correct owner emails
        $rosaOwnerEmail = $rosaEterna->users()->wherePivot('role', 'owner')->first()?->email;
        $this->assertSame('caro@rosaeterna.com', $rosaOwnerEmail);

        $tatianaOwnerEmail = $tatiana->users()->wherePivot('role', 'owner')->first()?->email;
        $this->assertSame('tati@regalostatiana.com', $tatianaOwnerEmail);
    }

    public function test_demo_tenants_seeder_creates_correct_staff_counts(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        // Rosa Eterna: 3 staff members
        $rosaStaff = $rosaEterna->users()->wherePivot('role', 'staff')->count();
        $this->assertSame(3, $rosaStaff, 'rosa-eterna should have 3 staff');

        // Tatiana: 1 staff + 1 admin
        $tatianaStaff = $tatiana->users()->wherePivot('role', 'staff')->count();
        $this->assertSame(1, $tatianaStaff, 'tatiana should have 1 staff');

        $tatianaAdmins = $tatiana->users()->wherePivot('role', 'admin')->count();
        $this->assertSame(1, $tatianaAdmins, 'tatiana should have 1 admin');
    }

    public function test_each_demo_tenant_has_a_main_branch(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        // Each tenant has exactly one branch with slug=main and is_main=true
        $rosaBranch = Branch::where('tenant_id', $rosaEterna->id)
            ->where('slug', 'main')
            ->where('is_main', true)
            ->first();

        $this->assertNotNull($rosaBranch, 'rosa-eterna should have a main branch');
        $this->assertSame(1, $rosaEterna->branches()->count());

        $tatianaBranch = Branch::where('tenant_id', $tatiana->id)
            ->where('slug', 'main')
            ->where('is_main', true)
            ->first();

        $this->assertNotNull($tatianaBranch, 'tatiana should have a main branch');
        $this->assertSame(1, $tatiana->branches()->count());
    }

    public function test_each_demo_tenant_has_a_trialing_subscription_on_correct_plan(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        $rosaSub = Subscription::where('tenant_id', $rosaEterna->id)->first();
        $this->assertNotNull($rosaSub, 'rosa-eterna should have a subscription');
        $this->assertSame('trialing', $rosaSub->status);
        $this->assertNotNull($rosaSub->trial_ends_at);
        $this->assertTrue($rosaSub->trial_ends_at->isFuture(), 'rosa-eterna trial_ends_at should be in the future');
        $this->assertSame('pro', $rosaSub->plan->slug);

        $tatianaSub = Subscription::where('tenant_id', $tatiana->id)->first();
        $this->assertNotNull($tatianaSub, 'tatiana should have a subscription');
        $this->assertSame('trialing', $tatianaSub->status);
        $this->assertNotNull($tatianaSub->trial_ends_at);
        $this->assertTrue($tatianaSub->trial_ends_at->isFuture(), 'tatiana trial_ends_at should be in the future');
        $this->assertSame('basico', $tatianaSub->plan->slug);
    }

    // ------------------------------------------------------------------ Idempotency

    public function test_seeders_are_idempotent_when_run_twice(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(SuperAdminUserSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        // Run the same seeders a second time — counts must not double
        $this->seed(PlansSeeder::class);
        $this->seed(SuperAdminUserSeeder::class);
        $this->seed(DemoTenantsSeeder::class);

        // Residual "demo" tenant removed in #28; total is now exactly 2.
        $this->assertSame(2, Tenant::count());
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertDatabaseCount('branches', 2);

        // 1 super-admin + 2 owners + 5 staff = 8
        $this->assertDatabaseCount('users', 8);

        // The tenant_users pivot should not have duplicates (2 owners + 5 staff = 7 rows)
        $this->assertSame(7, \DB::table('tenant_users')->count());
    }

    // ------------------------------------------------------------------ Orders seeder

    public function test_orders_seeder_creates_orders_for_each_demo_tenant(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana    = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        $rosaOrderCount = DB::table('orders')->where('tenant_id', $rosaEterna->id)->count();
        $tatianaOrderCount = DB::table('orders')->where('tenant_id', $tatiana->id)->count();

        $this->assertGreaterThanOrEqual(12, $rosaOrderCount, 'rosa-eterna should have at least 12 orders');
        $this->assertGreaterThanOrEqual(12, $tatianaOrderCount, 'tatiana should have at least 12 orders');
    }

    public function test_orders_seeder_covers_all_six_statuses(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);

        $seededStatuses = DB::table('orders')
            ->select('status')
            ->distinct()
            ->pluck('status')
            ->sort()
            ->values()
            ->all();

        $expectedStatuses = ['cancelled', 'delivered', 'dispatched', 'pending', 'preparing', 'ready'];

        $this->assertSame($expectedStatuses, $seededStatuses, 'All six order statuses must be represented');
    }

    public function test_every_order_has_at_least_one_item_and_a_unique_tracking_token(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);

        $orders = DB::table('orders')->get();

        $this->assertNotEmpty($orders, 'There must be at least one order');

        $seenTokens = [];

        foreach ($orders as $order) {
            // Every order must have at least one item.
            $itemCount = DB::table('order_items')->where('order_id', $order->id)->count();
            $this->assertGreaterThanOrEqual(
                1,
                $itemCount,
                "Order {$order->order_number} must have at least 1 item",
            );

            // tracking_token must be present and non-null.
            $this->assertNotNull(
                $order->tracking_token,
                "Order {$order->order_number} must have a tracking_token",
            );

            $this->assertSame(
                32,
                strlen((string) $order->tracking_token),
                "Order {$order->order_number} tracking_token must be 32 chars",
            );

            // tracking_token must be unique across all seeded orders.
            $this->assertArrayNotHasKey(
                $order->tracking_token,
                $seenTokens,
                "Duplicate tracking_token detected: {$order->tracking_token}",
            );

            $seenTokens[$order->tracking_token] = true;
        }
    }

    public function test_every_order_has_a_coherent_status_history(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);

        $orders = DB::table('orders')->get();

        foreach ($orders as $order) {
            $history = DB::table('order_status_history')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->get();

            // Every order must have at least one history row.
            $this->assertGreaterThanOrEqual(
                1,
                $history->count(),
                "Order {$order->order_number} must have at least 1 status history row",
            );

            // The last history row's to_status must equal the order's current status.
            $lastHistory = $history->last();
            $this->assertSame(
                $order->status,
                $lastHistory->to_status,
                "Last history row for order {$order->order_number} must match its status",
            );

            // The first history row must have from_status = null (creation entry).
            $firstHistory = $history->first();
            $this->assertNull(
                $firstHistory->from_status,
                "First history row for order {$order->order_number} must have from_status=null",
            );
        }
    }

    public function test_order_sequences_are_advanced_past_seeded_numbers(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);

        $year    = now()->year;
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $maxSeededSeq = DB::table('orders')
                ->where('tenant_id', $tenant->id)
                ->selectRaw("MAX(CAST(SUBSTRING_INDEX(order_number, '-', -1) AS UNSIGNED)) as max_seq")
                ->value('max_seq');

            if ($maxSeededSeq === null) {
                continue;  // tenant had no orders seeded
            }

            $lastSequence = DB::table('order_sequences')
                ->where('tenant_id', $tenant->id)
                ->where('year', $year)
                ->value('last_sequence');

            $this->assertNotNull(
                $lastSequence,
                "order_sequences row must exist for tenant {$tenant->slug} year {$year}",
            );

            $this->assertGreaterThanOrEqual(
                (int) $maxSeededSeq,
                (int) $lastSequence,
                "order_sequences.last_sequence ({$lastSequence}) must be >= highest seeded seq ({$maxSeededSeq}) for {$tenant->slug}",
            );
        }
    }

    public function test_orders_seeder_is_idempotent(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);

        $countAfterFirst = DB::table('orders')->count();

        // Running again must not add more rows.
        $this->seed(OrdersSeeder::class);

        $countAfterSecond = DB::table('orders')->count();

        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'OrdersSeeder must be idempotent — running it twice must not double the rows',
        );
    }

    // ------------------------------------------------------------------ Full pipeline

    public function test_database_seeder_runs_all_4_seeders_in_order(): void
    {
        $this->seed(DatabaseSeeder::class);

        // ReservedSubdomainsSeeder: 86 entries
        $this->assertDatabaseCount('reserved_subdomains', 86);

        // PlansSeeder: 3 plans
        $this->assertDatabaseCount('plans', 3);

        // SuperAdminUserSeeder (1) + 2 owners + 5 staff = 8 users
        $this->assertDatabaseCount('users', 8);

        // DemoTenantsSeeder: exactly 2 tenants — residual "demo" removed in #28.
        $this->assertSame(2, Tenant::count());

        // 2 subscriptions (one per tenant)
        $this->assertDatabaseCount('subscriptions', 2);

        // 2 branches (one main branch per tenant)
        $this->assertDatabaseCount('branches', 2);

        // OrdersSeeder: each tenant has orders seeded.
        foreach (Tenant::all() as $tenant) {
            $this->assertGreaterThan(
                0,
                DB::table('orders')->where('tenant_id', $tenant->id)->count(),
                "DatabaseSeeder pipeline must seed orders for tenant {$tenant->slug}",
            );
        }

        // ReservationsSeeder: each tenant has reservations seeded.
        foreach (Tenant::all() as $tenant) {
            $this->assertGreaterThan(
                0,
                DB::table('reservations')->where('tenant_id', $tenant->id)->count(),
                "DatabaseSeeder pipeline must seed reservations for tenant {$tenant->slug}",
            );
        }
    }

    // ------------------------------------------------------------------ Reservations seeder

    /**
     * @param  list<string>  $prerequisites
     */
    private function seedReservationPrerequisites(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);
        $this->seed(ReservationsSeeder::class);
    }

    public function test_reservations_seeder_creates_reservations_for_each_demo_tenant(): void
    {
        $this->seedReservationPrerequisites();

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana    = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        $rosaCount    = DB::table('reservations')->where('tenant_id', $rosaEterna->id)->count();
        $tatianaCount = DB::table('reservations')->where('tenant_id', $tatiana->id)->count();

        $this->assertGreaterThanOrEqual(10, $rosaCount, 'rosa-eterna should have at least 10 reservations');
        $this->assertGreaterThanOrEqual(10, $tatianaCount, 'tatiana should have at least 10 reservations');
    }

    public function test_reservations_seeder_covers_all_six_statuses_across_dataset(): void
    {
        $this->seedReservationPrerequisites();

        $seededStatuses = DB::table('reservations')
            ->select('status')
            ->distinct()
            ->pluck('status')
            ->sort()
            ->values()
            ->all();

        $expectedStatuses = ['cancelled', 'confirmed', 'delivered', 'in_progress', 'inquiry', 'ready'];

        $this->assertSame(
            $expectedStatuses,
            $seededStatuses,
            'All six reservation statuses must be represented across the seeded dataset',
        );
    }

    public function test_every_reservation_has_at_least_one_status_history_row_ending_at_current_status(): void
    {
        $this->seedReservationPrerequisites();

        $reservations = DB::table('reservations')->get();

        $this->assertNotEmpty($reservations, 'There must be at least one reservation');

        foreach ($reservations as $reservation) {
            $history = DB::table('reservation_status_history')
                ->where('reservation_id', $reservation->id)
                ->orderBy('created_at')
                ->get();

            $this->assertGreaterThanOrEqual(
                1,
                $history->count(),
                "Reservation {$reservation->reservation_number} must have at least 1 status history row",
            );

            $firstRow = $history->first();
            $this->assertNull(
                $firstRow->from_status,
                "First history row for {$reservation->reservation_number} must have from_status=null",
            );

            $lastRow = $history->last();
            $this->assertSame(
                $reservation->status,
                $lastRow->to_status,
                "Last history row for {$reservation->reservation_number} must match its status ({$reservation->status})",
            );
        }
    }

    public function test_deposit_paid_cents_equals_sum_of_payments_for_every_reservation(): void
    {
        $this->seedReservationPrerequisites();

        $reservations = DB::table('reservations')->get();

        foreach ($reservations as $reservation) {
            $paymentSum = (int) DB::table('reservation_payments')
                ->where('reservation_id', $reservation->id)
                ->sum('amount_cents');

            $this->assertSame(
                $paymentSum,
                (int) $reservation->deposit_paid_cents,
                "deposit_paid_cents ({$reservation->deposit_paid_cents}) must equal sum of payments "
                . "({$paymentSum}) for reservation {$reservation->reservation_number}",
            );
        }
    }

    public function test_inquiry_and_cancelled_reservations_have_no_payments(): void
    {
        $this->seedReservationPrerequisites();

        $noPaymentStatuses = ['inquiry', 'cancelled'];

        foreach ($noPaymentStatuses as $status) {
            $reservations = DB::table('reservations')->where('status', $status)->get();

            foreach ($reservations as $reservation) {
                $paymentCount = DB::table('reservation_payments')
                    ->where('reservation_id', $reservation->id)
                    ->count();

                $this->assertSame(
                    0,
                    $paymentCount,
                    "Reservation {$reservation->reservation_number} (status={$status}) should have 0 payments",
                );

                $this->assertSame(
                    0,
                    (int) $reservation->deposit_paid_cents,
                    "Reservation {$reservation->reservation_number} (status={$status}) deposit_paid_cents must be 0",
                );
            }
        }
    }

    public function test_demo_tenants_have_distinct_reservation_deposit_pct_and_occasions(): void
    {
        $this->seedReservationPrerequisites();

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana    = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        // Deposit percentages must differ (showcase per-tenant config).
        $this->assertSame(30, $rosaEterna->reservation_deposit_pct, 'rosa-eterna deposit_pct should be 30');
        $this->assertSame(50, $tatiana->reservation_deposit_pct, 'tatiana deposit_pct should be 50');

        // Both tenants must have a non-null, non-empty occasions list.
        $this->assertIsArray($rosaEterna->reservation_occasions);
        $this->assertNotEmpty($rosaEterna->reservation_occasions);

        $this->assertIsArray($tatiana->reservation_occasions);
        $this->assertNotEmpty($tatiana->reservation_occasions);

        // The two lists must be different (each tenant has their own occasions).
        $this->assertNotSame(
            $rosaEterna->reservation_occasions,
            $tatiana->reservation_occasions,
            'Each tenant must have a distinct customised occasions list',
        );
    }

    public function test_reservation_sequences_advanced_past_seeded_numbers(): void
    {
        $this->seedReservationPrerequisites();

        $year    = now()->year;
        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            $maxSeededSeq = DB::table('reservations')
                ->where('tenant_id', $tenant->id)
                ->selectRaw("MAX(CAST(SUBSTRING_INDEX(reservation_number, '-', -1) AS UNSIGNED)) as max_seq")
                ->value('max_seq');

            if ($maxSeededSeq === null) {
                continue;
            }

            $lastSequence = DB::table('reservation_sequences')
                ->where('tenant_id', $tenant->id)
                ->where('year', $year)
                ->value('last_sequence');

            $this->assertNotNull(
                $lastSequence,
                "reservation_sequences row must exist for tenant {$tenant->slug} year {$year}",
            );

            $this->assertGreaterThanOrEqual(
                (int) $maxSeededSeq,
                (int) $lastSequence,
                "reservation_sequences.last_sequence ({$lastSequence}) must be >= highest seeded seq "
                . "({$maxSeededSeq}) for {$tenant->slug}",
            );
        }
    }

    public function test_reservations_seeder_is_idempotent(): void
    {
        $this->seedReservationPrerequisites();

        $countAfterFirst = DB::table('reservations')->count();

        // Running again must not add more rows.
        $this->seed(ReservationsSeeder::class);

        $countAfterSecond = DB::table('reservations')->count();

        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'ReservationsSeeder must be idempotent — running it twice must not double the rows',
        );
    }

    public function test_no_cross_tenant_reservation_data_leakage(): void
    {
        $this->seedReservationPrerequisites();

        $tenants = Tenant::all();

        foreach ($tenants as $tenant) {
            // Every reservation_payment row must belong to the same tenant as its reservation.
            $crossLeakCount = DB::table('reservation_payments')
                ->join('reservations', 'reservation_payments.reservation_id', '=', 'reservations.id')
                ->where('reservations.tenant_id', $tenant->id)
                ->where('reservation_payments.tenant_id', '!=', $tenant->id)
                ->count();

            $this->assertSame(
                0,
                $crossLeakCount,
                "Cross-tenant payment leak detected for tenant {$tenant->slug}",
            );

            // Every status history row must match its reservation's tenant.
            $historyLeakCount = DB::table('reservation_status_history')
                ->join('reservations', 'reservation_status_history.reservation_id', '=', 'reservations.id')
                ->where('reservations.tenant_id', $tenant->id)
                ->where('reservation_status_history.tenant_id', '!=', $tenant->id)
                ->count();

            $this->assertSame(
                0,
                $historyLeakCount,
                "Cross-tenant history leak detected for tenant {$tenant->slug}",
            );
        }
    }

    // ------------------------------------------------------------------ Expenses seeder

    /**
     * Seed all prerequisites for the expenses seeder tests.
     * Categories must exist before expenses are seeded.
     */
    private function seedExpensePrerequisites(): void
    {
        $this->seed(PlansSeeder::class);
        $this->seed(DemoTenantsSeeder::class);
        $this->seed(CategoriesSeeder::class);
        $this->seed(TagsSeeder::class);
        $this->seed(ProductsSeeder::class);
        $this->seed(BranchInventorySeeder::class);
        $this->seed(OrdersSeeder::class);
        $this->seed(ReservationsSeeder::class);
        $this->seed(ExpenseCategoriesSeeder::class);
        $this->seed(ExpensesSeeder::class);
    }

    public function test_expenses_seeder_creates_expenses_for_each_demo_tenant(): void
    {
        $this->seedExpensePrerequisites();

        $rosaEterna = Tenant::findBySlug('rosa-eterna');
        $tatiana    = Tenant::findBySlug('tatiana');

        $this->assertNotNull($rosaEterna);
        $this->assertNotNull($tatiana);

        $rosaCount    = DB::table('expenses')->where('tenant_id', $rosaEterna->id)->count();
        $tatianaCount = DB::table('expenses')->where('tenant_id', $tatiana->id)->count();

        $this->assertGreaterThanOrEqual(25, $rosaCount, 'rosa-eterna should have at least 25 expenses');
        $this->assertGreaterThanOrEqual(25, $tatianaCount, 'tatiana should have at least 25 expenses');
    }

    public function test_expenses_seeder_covers_at_least_three_distinct_months_per_tenant(): void
    {
        $this->seedExpensePrerequisites();

        foreach (Tenant::all() as $tenant) {
            $distinctMonths = DB::table('expenses')
                ->where('tenant_id', $tenant->id)
                ->selectRaw("DATE_FORMAT(expense_date, '%Y-%m') as ym")
                ->distinct()
                ->pluck('ym');

            $this->assertGreaterThanOrEqual(
                3,
                $distinctMonths->count(),
                "Tenant {$tenant->slug} must have expenses spanning at least 3 distinct months "
                . "(got {$distinctMonths->count()}: {$distinctMonths->implode(', ')})",
            );
        }
    }

    public function test_expenses_seeder_uses_all_five_categories_and_has_uncategorised_rows(): void
    {
        $this->seedExpensePrerequisites();

        foreach (Tenant::all() as $tenant) {
            // All 5 category types must appear (resolved by joining to expense_categories.type).
            $usedTypes = DB::table('expenses')
                ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
                ->where('expenses.tenant_id', $tenant->id)
                ->select('expense_categories.type')
                ->distinct()
                ->pluck('type')
                ->sort()
                ->values()
                ->all();

            $expectedTypes = ['operating', 'other', 'payroll', 'products', 'rent'];

            $this->assertSame(
                $expectedTypes,
                $usedTypes,
                "Tenant {$tenant->slug} must use all 5 category types (got: " . implode(', ', $usedTypes) . ")",
            );

            // At least one uncategorised expense must exist per tenant.
            $uncategorisedCount = DB::table('expenses')
                ->where('tenant_id', $tenant->id)
                ->whereNull('expense_category_id')
                ->count();

            $this->assertGreaterThanOrEqual(
                1,
                $uncategorisedCount,
                "Tenant {$tenant->slug} must have at least 1 uncategorised expense (expense_category_id=null)",
            );
        }
    }

    public function test_expenses_seeder_includes_ocr_done_verified_and_ocr_draft_rows(): void
    {
        $this->seedExpensePrerequisites();

        foreach (Tenant::all() as $tenant) {
            // At least one OCR-done + verified row (receipt uploaded, staff confirmed).
            $ocrVerifiedCount = DB::table('expenses')
                ->where('tenant_id', $tenant->id)
                ->where('ocr_status', 'done')
                ->where('is_verified', true)
                ->count();

            $this->assertGreaterThanOrEqual(
                1,
                $ocrVerifiedCount,
                "Tenant {$tenant->slug} must have at least 1 OCR-done+verified expense",
            );

            // At least one OCR draft (done but not yet confirmed) per tenant.
            $ocrDraftCount = DB::table('expenses')
                ->where('tenant_id', $tenant->id)
                ->where('ocr_status', 'done')
                ->where('is_verified', false)
                ->count();

            $this->assertGreaterThanOrEqual(
                1,
                $ocrDraftCount,
                "Tenant {$tenant->slug} must have at least 1 OCR-done draft (is_verified=false) "
                . "for the E7 verification queue",
            );
        }
    }

    public function test_ocr_done_expenses_have_receipt_path_and_ocr_data(): void
    {
        $this->seedExpensePrerequisites();

        $ocrDoneExpenses = DB::table('expenses')
            ->where('ocr_status', 'done')
            ->get();

        $this->assertNotEmpty($ocrDoneExpenses, 'There must be at least one ocr_status=done expense');

        foreach ($ocrDoneExpenses as $expense) {
            $this->assertNotNull(
                $expense->receipt_path,
                "Expense id={$expense->id} with ocr_status=done must have a receipt_path",
            );

            $this->assertNotNull(
                $expense->ocr_data,
                "Expense id={$expense->id} with ocr_status=done must have ocr_data",
            );

            // ocr_data must decode to a valid JSON object with the expected keys.
            $decoded = json_decode((string) $expense->ocr_data, associative: true, flags: JSON_THROW_ON_ERROR);

            $this->assertArrayHasKey('vendor', $decoded, "ocr_data must contain 'vendor'");
            $this->assertArrayHasKey('amount_cents', $decoded, "ocr_data must contain 'amount_cents'");
            $this->assertArrayHasKey('date', $decoded, "ocr_data must contain 'date'");
            $this->assertArrayHasKey('raw_text', $decoded, "ocr_data must contain 'raw_text'");
            $this->assertArrayHasKey('confidence', $decoded, "ocr_data must contain 'confidence'");

            $this->assertIsInt($decoded['amount_cents'], "ocr_data.amount_cents must be an integer");
            $this->assertGreaterThan(0, $decoded['amount_cents'], "ocr_data.amount_cents must be positive");
        }
    }

    public function test_all_expense_amounts_are_positive_integers(): void
    {
        $this->seedExpensePrerequisites();

        $expenses = DB::table('expenses')->get();

        $this->assertNotEmpty($expenses, 'There must be at least one expense');

        foreach ($expenses as $expense) {
            $this->assertGreaterThan(
                0,
                (int) $expense->amount_cents,
                "Expense id={$expense->id} must have amount_cents > 0 (got {$expense->amount_cents})",
            );
        }
    }

    public function test_no_cross_tenant_expense_data_leakage(): void
    {
        $this->seedExpensePrerequisites();

        foreach (Tenant::all() as $tenant) {
            // Every expense's category (when set) must belong to the same tenant.
            $crossLeakCount = DB::table('expenses')
                ->join('expense_categories', 'expenses.expense_category_id', '=', 'expense_categories.id')
                ->where('expenses.tenant_id', $tenant->id)
                ->where('expense_categories.tenant_id', '!=', $tenant->id)
                ->count();

            $this->assertSame(
                0,
                $crossLeakCount,
                "Cross-tenant category leak detected for tenant {$tenant->slug}: "
                . "an expense references a category from another tenant",
            );
        }
    }

    public function test_expenses_seeder_is_idempotent(): void
    {
        $this->seedExpensePrerequisites();

        $countAfterFirst = DB::table('expenses')->count();

        // Running again must not add more rows.
        $this->seed(ExpensesSeeder::class);

        $countAfterSecond = DB::table('expenses')->count();

        $this->assertSame(
            $countAfterFirst,
            $countAfterSecond,
            'ExpensesSeeder must be idempotent — running it twice must not double the rows',
        );
    }

    public function test_database_seeder_pipeline_includes_expenses(): void
    {
        $this->seed(DatabaseSeeder::class);

        // Each demo tenant must have expenses after the full seed pipeline.
        foreach (Tenant::all() as $tenant) {
            $this->assertGreaterThan(
                0,
                DB::table('expenses')->where('tenant_id', $tenant->id)->count(),
                "DatabaseSeeder pipeline must seed expenses for tenant {$tenant->slug}",
            );
        }
    }
}
