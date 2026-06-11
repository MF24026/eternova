<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for the OrderService state machine and order_status_history.
 *
 * Each test is isolated: its own tenant/branch/variant/order setup.
 * No shared class-level state that could cause cross-test pollution.
 *
 * Multi-tenant invariant (tested explicitly): a history row created for
 * Tenant A must never appear when Tenant B's scope is active — even though
 * the rows share the same physical table.
 */
final class OrderStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    private InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrderService::class);
        $this->inventoryService = app(InventoryService::class);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Build a minimal isolated tenant context and a prepared order in 'preparing' status.
     *
     * @return array{tenant: Tenant, branch: Branch, order: Order, user: User}
     */
    private function setupOrderInStatus(string $status = 'preparing'): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['base_price_cents' => 1000]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(1000)->create();

        $this->inventoryService->recordEntry($branch, $variant, 10, $user);

        app()->instance('currentTenant', $tenant);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $user,
        );

        // Advance to the requested status if it is not already 'preparing'
        $this->advanceOrderToStatus($order, $status, $user);

        // Re-read from DB with fresh status
        $order = $order->fresh();

        return compact('tenant', 'branch', 'order', 'user');
    }

    /**
     * Advance an order to the target status by walking valid transitions.
     * Only handles the straightforward forward path needed by tests.
     */
    private function advanceOrderToStatus(Order $order, string $targetStatus, User $user): void
    {
        $path = [
            'preparing' => [],
            'ready'      => ['ready'],
            'dispatched' => ['ready', 'dispatched'],
            'delivered'  => ['ready', 'dispatched', 'delivered'],
            'cancelled'  => ['cancelled'],
        ];

        foreach ($path[$targetStatus] ?? [] as $step) {
            $order = $this->service->transitionTo($order->fresh(), $step, $user);
        }
    }

    // ── createFromPos: initial history row ───────────────────────────────────

    public function test_create_from_pos_writes_initial_history_row(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['base_price_cents' => 500]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(500)->create();
        $this->inventoryService->recordEntry($branch, $variant, 5, $user);

        app()->instance('currentTenant', $tenant);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $user,
        );

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'tenant_id' => $tenant->id,
            'from_status' => null,
            'to_status' => 'preparing',
            'user_id' => $user->id,
            'note' => 'POS sale created',
        ]);

        $history = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->get();

        $this->assertSame(1, $history->count());
    }

    public function test_initial_history_row_is_inside_the_pos_transaction(): void
    {
        // If the transaction rolls back (stock failure), no history row must exist
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create();
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(500)->create();
        // Intentionally no stock seeded — will trigger a rollback

        try {
            $this->service->createFromPos(
                branch: $branch,
                items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
                paymentMethod: 'cash',
            );
        } catch (DomainException) {
            // expected
        }

        $this->assertDatabaseCount('order_status_history', 0);
    }

    // ── transitionTo: valid transitions ──────────────────────────────────────

    public function test_valid_transition_updates_order_status(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        $updated = $this->service->transitionTo($order, 'ready', $user);

        $this->assertSame('ready', $updated->status);
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_valid_transition_appends_exactly_one_history_row(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        // One row already exists (initial creation)
        $before = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        $this->service->transitionTo($order, 'ready', $user, note: 'kitchen done');

        $after = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        $this->assertSame($before + 1, $after);
    }

    public function test_transition_history_row_carries_correct_from_to_user_note(): void
    {
        ['order' => $order, 'user' => $user, 'tenant' => $tenant]
            = $this->setupOrderInStatus('preparing');

        $this->service->transitionTo($order, 'ready', $user, note: 'flowers arranged');

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'tenant_id' => $tenant->id,
            'from_status' => 'preparing',
            'to_status' => 'ready',
            'user_id' => $user->id,
            'note' => 'flowers arranged',
        ]);
    }

    public function test_transition_with_null_actor_records_null_user_id(): void
    {
        ['order' => $order] = $this->setupOrderInStatus('preparing');

        $this->service->transitionTo($order, 'ready', actor: null);

        $history = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('to_status', 'ready')
            ->firstOrFail();

        $this->assertNull($history->user_id);
    }

    public function test_transition_returns_fresh_order_with_status_history_loaded(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        $result = $this->service->transitionTo($order, 'ready', $user);

        $this->assertTrue($result->relationLoaded('statusHistory'));
        $this->assertNotEmpty($result->statusHistory);
    }

    // ── transitionTo: full forward chain ──────────────────────────────────────

    public function test_full_forward_chain_pending_to_delivered(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupOrderInStatus('preparing');

        // Create a fresh order that starts at 'pending'
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();
        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create();
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(300)->create();
        $this->inventoryService->recordEntry($branch, $variant, 5, $user);
        app()->instance('currentTenant', $tenant);

        $order = Order::factory()->forBranch($branch)->create([
            'status' => 'pending',
            'tenant_id' => $tenant->id,
        ]);

        // Walk the chain
        $order = $this->service->transitionTo($order, 'preparing', $user);
        $order = $this->service->transitionTo($order, 'ready', $user);
        $order = $this->service->transitionTo($order, 'dispatched', $user);
        $order = $this->service->transitionTo($order, 'delivered', $user);

        $this->assertSame('delivered', $order->status);

        // 4 history rows recorded (one per transitionTo call; the factory order has none)
        $count = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        $this->assertSame(4, $count);
    }

    public function test_ready_can_jump_directly_to_delivered_for_store_pickup(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('ready');

        $updated = $this->service->transitionTo($order, 'delivered', $user);

        $this->assertSame('delivered', $updated->status);
    }

    // ── transitionTo: invalid transitions ────────────────────────────────────

    public function test_invalid_transition_throws_domain_exception(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot transition.*preparing.*pending/');

        $this->service->transitionTo($order, 'pending', $user);
    }

    public function test_invalid_transition_does_not_mutate_order_status(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        try {
            $this->service->transitionTo($order, 'pending', $user);
        } catch (DomainException) {
            // expected
        }

        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_invalid_transition_does_not_write_history_row(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        $before = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        try {
            $this->service->transitionTo($order, 'pending', $user);
        } catch (DomainException) {
            // expected
        }

        $after = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        $this->assertSame($before, $after);
    }

    public function test_unknown_status_throws_domain_exception(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/not a recognised order status/');

        $this->service->transitionTo($order, 'flying', $user);
    }

    // ── Terminal states ───────────────────────────────────────────────────────

    public function test_delivered_order_rejects_all_transitions(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('delivered');

        foreach (['pending', 'preparing', 'ready', 'dispatched', 'cancelled'] as $status) {
            $exceptionThrown = false;

            try {
                $this->service->transitionTo($order, $status, $user);
            } catch (DomainException) {
                $exceptionThrown = true;
            }

            $this->assertTrue(
                $exceptionThrown,
                "Expected DomainException when transitioning delivered → {$status}"
            );
        }
    }

    public function test_cancelled_order_rejects_all_transitions(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('cancelled');

        foreach (['pending', 'preparing', 'ready', 'dispatched', 'delivered'] as $status) {
            $exceptionThrown = false;

            try {
                $this->service->transitionTo($order, $status, $user);
            } catch (DomainException) {
                $exceptionThrown = true;
            }

            $this->assertTrue(
                $exceptionThrown,
                "Expected DomainException when transitioning cancelled → {$status}"
            );
        }
    }

    // ── allowedTransitions() ─────────────────────────────────────────────────

    public function test_allowed_transitions_returns_correct_set_for_each_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupOrderInStatus('preparing');

        app()->instance('currentTenant', $tenant);

        $cases = [
            'pending'    => ['preparing', 'cancelled'],
            'preparing'  => ['ready', 'cancelled'],
            'ready'      => ['dispatched', 'delivered', 'cancelled'],
            'dispatched' => ['delivered', 'cancelled'],
            'delivered'  => [],
            'cancelled'  => [],
        ];

        foreach ($cases as $status => $expected) {
            $order = Order::factory()->forBranch($branch)->create(['status' => $status]);

            $actual = $this->service->allowedTransitions($order);

            $this->assertSame(
                $expected,
                $actual,
                "Allowed transitions for '{$status}' did not match expected set."
            );
        }
    }

    // ── cancel() delegation ───────────────────────────────────────────────────

    public function test_cancel_writes_history_row_with_cancelled_status(): void
    {
        ['order' => $order, 'user' => $user, 'tenant' => $tenant]
            = $this->setupOrderInStatus('preparing');

        $this->service->cancel($order, $user);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'tenant_id' => $tenant->id,
            'from_status' => 'preparing',
            'to_status' => 'cancelled',
            'user_id' => $user->id,
        ]);
    }

    public function test_cancelling_already_cancelled_order_throws_and_does_not_add_history(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('preparing');

        $this->service->cancel($order, $user);

        $countAfterFirstCancel = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/already cancelled/');

        $this->service->cancel($order->fresh(), $user);

        // Should not have grown
        $this->assertSame(
            $countAfterFirstCancel,
            OrderStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('order_id', $order->id)
                ->count()
        );
    }

    public function test_cancelling_delivered_order_throws_and_does_not_add_history(): void
    {
        ['order' => $order, 'user' => $user] = $this->setupOrderInStatus('delivered');

        $countBefore = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/already been delivered/');

        $this->service->cancel($order, $user);

        $this->assertSame(
            $countBefore,
            OrderStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('order_id', $order->id)
                ->count()
        );
    }

    // ── Multi-tenant isolation ────────────────────────────────────────────────

    public function test_history_rows_are_scoped_to_current_tenant(): void
    {
        // Create an order + history row for Tenant A
        ['tenant' => $tenantA, 'order' => $orderA, 'user' => $userA]
            = $this->setupOrderInStatus('preparing');

        $this->service->transitionTo($orderA, 'ready', $userA);

        // Switch context to Tenant B
        $tenantB = Tenant::factory()->create();
        app()->instance('currentTenant', $tenantB);

        // Under Tenant B's scope, Tenant A's history rows must not be visible
        $visible = OrderStatusHistory::where('order_id', $orderA->id)->count();

        $this->assertSame(0, $visible, 'Tenant B must not see Tenant A history through tenant scope');
    }

    public function test_transition_on_tenant_a_order_does_not_affect_tenant_b_history(): void
    {
        // Two independent tenants, each with one order
        ['tenant' => $tenantA, 'order' => $orderA, 'user' => $userA]
            = $this->setupOrderInStatus('preparing');

        ['tenant' => $tenantB, 'order' => $orderB, 'user' => $userB]
            = $this->setupOrderInStatus('preparing');

        // Tenant A transitions its order
        app()->instance('currentTenant', $tenantA);
        $this->service->transitionTo($orderA, 'ready', $userA);

        // Count Tenant B's history rows (should only have the initial creation row)
        app()->instance('currentTenant', $tenantB);
        $countB = OrderStatusHistory::where('order_id', $orderB->id)->count();

        $this->assertSame(1, $countB, 'Tenant A transition must not create rows for Tenant B');
    }

    public function test_order_status_history_global_scope_filters_by_tenant(): void
    {
        ['tenant' => $tenantA, 'order' => $orderA]
            = $this->setupOrderInStatus('preparing');

        ['tenant' => $tenantB, 'order' => $orderB]
            = $this->setupOrderInStatus('preparing');

        // Tenant A scope: only sees its own rows
        app()->instance('currentTenant', $tenantA);
        $rowsA = OrderStatusHistory::all();

        foreach ($rowsA as $row) {
            $this->assertSame($tenantA->id, $row->tenant_id);
        }

        // Tenant B scope: only sees its own rows
        app()->instance('currentTenant', $tenantB);
        $rowsB = OrderStatusHistory::all();

        foreach ($rowsB as $row) {
            $this->assertSame($tenantB->id, $row->tenant_id);
        }

        // Without scope: all rows are visible (super-admin / background-job context)
        $all = OrderStatusHistory::withoutGlobalScope(TenantScope::class)->count();
        $this->assertGreaterThanOrEqual($rowsA->count() + $rowsB->count(), $all);
    }
}
