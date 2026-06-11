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
 * Feature tests for OrderService::assign().
 *
 * Verifies:
 *  - Happy-path assignment sets assigned_to and resolves the assignee relation
 *  - Passing null clears the assignment (un-assign)
 *  - Cross-tenant assignment throws DomainException and does NOT mutate the order
 *  - Every assignment writes a timeline history row (status is unchanged)
 *  - Multi-tenant isolation: assignment on tenant A is invisible to tenant B
 *  - Re-assigning to a different user updates assigned_to correctly
 *
 * Each test creates its own tenant context to stay independent — no shared state.
 */
final class OrderAssignmentTest extends TestCase
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
     * Build a minimal isolated tenant context: tenant, branch, product, variant,
     * a staff user, and a prepared order in 'preparing' status.
     *
     * @return array{tenant: Tenant, branch: Branch, order: Order, user: User}
     */
    private function setupOrderContext(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['base_price_cents' => 1000]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(1000)->create();

        $this->inventoryService->recordEntry($branch, $variant, 5, $user);

        app()->instance('currentTenant', $tenant);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $user,
        );

        return compact('tenant', 'branch', 'order', 'user');
    }

    // ── Assign: happy path ────────────────────────────────────────────────────

    public function test_assign_sets_assigned_to_and_loads_assignee_relation(): void
    {
        ['tenant' => $tenant, 'order' => $order, 'user' => $user] = $this->setupOrderContext();

        $assignee = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $result = $this->service->assign($order, $assignee, actor: $user);

        $this->assertSame($assignee->id, $result->assigned_to);
        $this->assertTrue($result->relationLoaded('assignee'));
        $this->assertSame($assignee->id, $result->assignee->id);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    public function test_assign_null_clears_the_assignment(): void
    {
        ['tenant' => $tenant, 'order' => $order, 'user' => $user] = $this->setupOrderContext();

        $assignee = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        // First assign someone
        $this->service->assign($order, $assignee, actor: $user);

        // Then un-assign
        $result = $this->service->assign($order->fresh(), null, actor: $user);

        $this->assertNull($result->assigned_to);
        $this->assertNull($result->assignee);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'assigned_to' => null,
        ]);
    }

    // ── Cross-tenant guard ────────────────────────────────────────────────────

    public function test_assigning_user_from_another_tenant_throws_domain_exception(): void
    {
        ['order' => $order, 'user' => $actor] = $this->setupOrderContext();

        // Create a user that belongs ONLY to a different tenant — never to the order's tenant
        $otherTenant = Tenant::factory()->create();
        $foreignUser = User::factory()->forTenant($otherTenant, role: 'staff')->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot assign order.*another tenant/');

        $this->service->assign($order, $foreignUser, actor: $actor);
    }

    public function test_cross_tenant_assignment_does_not_mutate_the_order(): void
    {
        ['tenant' => $tenant, 'order' => $order, 'user' => $actor] = $this->setupOrderContext();

        $originalAssignedTo = $order->assigned_to;

        $otherTenant = Tenant::factory()->create();
        $foreignUser = User::factory()->forTenant($otherTenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        try {
            $this->service->assign($order, $foreignUser, actor: $actor);
        } catch (DomainException) {
            // expected
        }

        // assigned_to must not have changed
        $this->assertSame($originalAssignedTo, $order->fresh()->assigned_to);
    }

    // ── Timeline / history ────────────────────────────────────────────────────

    public function test_assign_writes_a_history_row_without_changing_status(): void
    {
        ['tenant' => $tenant, 'order' => $order, 'user' => $actor] = $this->setupOrderContext();

        $assignee = User::factory()->forTenant($tenant, role: 'staff')->create();

        $statusBefore = $order->status;

        app()->instance('currentTenant', $tenant);

        $this->service->assign($order, $assignee, actor: $actor);

        // Status must be unchanged
        $this->assertSame($statusBefore, $order->fresh()->status);

        // A history row must have been appended
        $row = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('from_status', $statusBefore)
            ->where('to_status', $statusBefore)
            ->latest('created_at')
            ->first();

        $this->assertNotNull($row, 'Expected an assignment history row with from_status === to_status');
        $this->assertSame($actor->id, $row->user_id);
        $this->assertStringContainsString($assignee->name, $row->note);
    }

    public function test_unassign_writes_history_row_with_unassigned_note(): void
    {
        ['tenant' => $tenant, 'order' => $order, 'user' => $actor] = $this->setupOrderContext();

        $assignee = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $this->service->assign($order, $assignee, actor: $actor);
        $this->service->assign($order->fresh(), null, actor: $actor);

        $row = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->where('note', 'Unassigned')
            ->first();

        $this->assertNotNull($row, 'Expected an "Unassigned" history row');
    }

    public function test_assignment_does_not_disturb_transition_history_count(): void
    {
        ['tenant' => $tenant, 'order' => $order, 'user' => $actor] = $this->setupOrderContext();

        $assignee = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        // One history row already exists from createFromPos (initial creation row)
        $countBefore = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        $this->service->assign($order, $assignee, actor: $actor);

        $countAfter = OrderStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('order_id', $order->id)
            ->count();

        // Exactly one row appended
        $this->assertSame($countBefore + 1, $countAfter);
    }

    // ── Multi-tenant isolation ────────────────────────────────────────────────

    public function test_assignment_on_tenant_a_is_invisible_to_tenant_b(): void
    {
        ['tenant' => $tenantA, 'order' => $orderA, 'user' => $actorA] = $this->setupOrderContext();

        $assigneeA = User::factory()->forTenant($tenantA, role: 'staff')->create();

        app()->instance('currentTenant', $tenantA);

        $this->service->assign($orderA, $assigneeA, actor: $actorA);

        // Switch to Tenant B
        $tenantB = Tenant::factory()->create();
        app()->instance('currentTenant', $tenantB);

        // Tenant B must not see the order at all
        $found = Order::find($orderA->id);
        $this->assertNull($found, 'Tenant B must not see Tenant A orders through tenant scope');

        // Tenant B must not see Tenant A history rows
        $visible = OrderStatusHistory::where('order_id', $orderA->id)->count();
        $this->assertSame(0, $visible, 'Tenant B must not see Tenant A history rows');
    }

    // ── Re-assign ─────────────────────────────────────────────────────────────

    public function test_re_assigning_to_different_user_updates_assigned_to(): void
    {
        ['tenant' => $tenant, 'order' => $order, 'user' => $actor] = $this->setupOrderContext();

        $firstAssignee = User::factory()->forTenant($tenant, role: 'staff')->create();
        $secondAssignee = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $this->service->assign($order, $firstAssignee, actor: $actor);

        $result = $this->service->assign($order->fresh(), $secondAssignee, actor: $actor);

        $this->assertSame($secondAssignee->id, $result->assigned_to);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'assigned_to' => $secondAssignee->id,
        ]);
    }
}
