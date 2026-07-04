<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the Orders Admin API (S4-E3).
 *
 * Verifies:
 *   - index returns paginated orders scoped to the current tenant only
 *   - index status_counts spans all statuses and ignores the status filter
 *   - index filters (branch_id, status, date_range, search) work correctly
 *   - show returns full detail (items + timeline + assignee); cross-tenant id → 404
 *   - transition advances status (valid) and 422s on invalid transition
 *   - assign endpoint assigns and un-assigns; 422 on cross-tenant assignee
 *   - cancel endpoint cancels; 422 on already-delivered order
 *   - unauthenticated → 401; authenticated-but-other-tenant → 404
 *
 * Each test creates its own isolated tenant context — no shared state that
 * could cause cross-test pollution or flaky ordering.
 */
final class OrderApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private InventoryService $inventoryService;

    private OrderService $orderService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryService = app(InventoryService::class);
        $this->orderService = app(OrderService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal tenant with a branch, an owner user, a product, a variant,
     * and (optionally) seed stock so orders can be created.
     *
     * @return array{tenant: Tenant, branch: Branch, owner: User, product: Product, variant: ProductVariant}
     */
    private function setupTenant(int $stock = 20): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['base_price_cents' => 1500]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(1500)->create();

        $this->inventoryService->recordEntry($branch, $variant, $stock, $owner);

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner', 'product', 'variant');
    }

    /**
     * Create an order via the service so it has a proper status history row.
     */
    private function createOrder(Branch $branch, ProductVariant $variant, User $user): Order
    {
        app()->instance('currentTenant', Tenant::find($branch->tenant_id));

        return $this->orderService->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $user,
        );
    }

    // ── index: auth gates ─────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->tenantUrl($tenant, 'api/v1/orders'))
            ->assertStatus(401);
    }

    public function test_customer_role_cannot_list_orders(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $customer = User::factory()->forTenant($tenant, role: 'customer')->create();

        $this->tenantGetJson($tenant, $customer, '/api/v1/orders')
            ->assertStatus(403);
    }

    // ── index: pagination + tenant isolation ─────────────────────────────────

    public function test_index_returns_paginated_orders_for_current_tenant_only(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA, 'owner' => $ownerA, 'variant' => $variantA]
            = $this->setupTenant();

        // Create 2 orders for tenant A
        $this->createOrder($branchA, $variantA, $ownerA);
        $this->createOrder($branchA, $variantA, $ownerA);

        // Create 3 orders for tenant B — must not appear in tenant A's response
        ['branch' => $branchB, 'variant' => $variantB, 'owner' => $ownerB]
            = $this->setupTenant();
        $this->createOrder($branchB, $variantB, $ownerB);
        $this->createOrder($branchB, $variantB, $ownerB);
        $this->createOrder($branchB, $variantB, $ownerB);

        $response = $this->tenantGetJson($tenantA, $ownerA, '/api/v1/orders')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id'],
                'status_counts',
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    // ── index: status_counts ──────────────────────────────────────────────────

    public function test_index_status_counts_returns_all_six_statuses(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/orders')
            ->assertOk();

        $counts = $response->json('status_counts');

        $this->assertArrayHasKey('pending', $counts);
        $this->assertArrayHasKey('preparing', $counts);
        $this->assertArrayHasKey('ready', $counts);
        $this->assertArrayHasKey('dispatched', $counts);
        $this->assertArrayHasKey('delivered', $counts);
        $this->assertArrayHasKey('cancelled', $counts);
    }

    public function test_index_status_counts_are_correct_values(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        // 2 preparing (from createFromPos), 1 pending (manual factory)
        $this->createOrder($branch, $variant, $owner);
        $this->createOrder($branch, $variant, $owner);
        Order::factory()->forBranch($branch)->create(['status' => 'pending']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/orders')
            ->assertOk();

        $counts = $response->json('status_counts');

        $this->assertSame(1, $counts['pending']);
        $this->assertSame(2, $counts['preparing']);
        $this->assertSame(0, $counts['ready']);
    }

    public function test_index_status_counts_ignore_the_status_filter(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        $this->createOrder($branch, $variant, $owner);  // preparing
        Order::factory()->forBranch($branch)->create(['status' => 'pending']);

        // Filter by status=preparing → data only has preparing orders,
        // but counts must still include pending=1
        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/orders?status=preparing')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));

        $counts = $response->json('status_counts');
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(1, $counts['preparing']);
    }

    // ── index: filters ────────────────────────────────────────────────────────

    public function test_index_filter_by_branch_id(): void
    {
        ['tenant' => $tenant, 'branch' => $branchA, 'owner' => $owner, 'variant' => $variantA]
            = $this->setupTenant();

        // Second branch in the same tenant
        app()->instance('currentTenant', $tenant);
        $branchB = Branch::factory()->forTenant($tenant)->create();
        $productB = Product::factory()->forTenant($tenant)->create(['base_price_cents' => 500]);
        $variantB = ProductVariant::factory()->forProduct($productB)->withPrice(500)->create();
        $this->inventoryService->recordEntry($branchB, $variantB, 5, $owner);
        app()->instance('currentTenant', $tenant);

        $this->createOrder($branchA, $variantA, $owner);

        app()->instance('currentTenant', $tenant);
        $this->orderService->createFromPos(
            branch: $branchB,
            items: [['product_variant_id' => $variantB->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $owner,
        );

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/orders?branch_id={$branchA->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($branchA->id, $response->json('data.0.branch.id'));
    }

    public function test_index_filter_by_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        app()->instance('currentTenant', $tenant);
        $this->createOrder($branch, $variant, $owner);  // preparing
        Order::factory()->forBranch($branch)->create(['status' => 'pending']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/orders?status=pending')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('pending', $response->json('data.0.status'));
    }

    public function test_index_filter_by_date_range(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner]
            = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        // One order "in the past", one "today"
        Order::factory()->forBranch($branch)->create([
            'created_at' => now()->subDays(10),
        ]);
        Order::factory()->forBranch($branch)->create([
            'created_at' => now(),
        ]);

        $from = now()->subDays(1)->toDateString();
        $to = now()->toDateString();

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/orders?date_from={$from}&date_to={$to}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_search_by_order_number(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner]
            = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        Order::factory()->forBranch($branch)->create(['order_number' => 'CC-2026-0042']);
        Order::factory()->forBranch($branch)->create(['order_number' => 'CC-2026-0099']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/orders?search=0042')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('CC-2026-0042', $response->json('data.0.order_number'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_order_with_items_timeline_and_assignee(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $assignee = User::factory()->forTenant($tenant, role: 'staff')->create();
        app()->instance('currentTenant', $tenant);
        $this->orderService->assign($order, $assignee, actor: $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'order_number',
                    'status',
                    'items',
                    'status_history',
                    'assignee' => ['id', 'name'],
                    'allowed_transitions',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.items'));
        $this->assertNotEmpty($response->json('data.status_history'));
        $this->assertSame($assignee->id, $response->json('data.assignee.id'));
    }

    public function test_show_cross_tenant_order_id_is_not_accessible(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['branch' => $branchB, 'variant' => $variantB, 'owner' => $ownerB] = $this->setupTenant();

        $orderB = $this->createOrder($branchB, $variantB, $ownerB);

        // Tenant A user tries to access tenant B's order by id.
        // BelongsToTenant scope hides the resource → 404.
        // If the policy fires first (implementation detail) → 403.
        // Either outcome correctly denies access — we assert both are acceptable.
        $response = $this->tenantGetJson($tenantA, $ownerA, "/api/v1/orders/{$orderB->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    // ── transition ────────────────────────────────────────────────────────────

    public function test_transition_advances_order_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);
        $this->assertSame('preparing', $order->status);

        $response = $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/status",
            ['status' => 'ready'],
        )->assertOk();

        $this->assertSame('ready', $response->json('data.status'));
        $this->assertSame('ready', $order->fresh()->status);
    }

    public function test_transition_with_note_stores_note_in_history(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/status",
            ['status' => 'ready', 'note' => 'arreglo listo'],
        )->assertOk();

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'to_status' => 'ready',
            'note' => 'arreglo listo',
        ]);
    }

    public function test_invalid_transition_returns_422(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);
        // 'preparing' → 'delivered' is not a valid transition

        $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/status",
            ['status' => 'delivered'],
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'orders.invalid_transition');

        // Status must not have changed
        $this->assertSame('preparing', $order->fresh()->status);
    }

    public function test_transition_returns_allowed_transitions_for_new_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $response = $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/status",
            ['status' => 'ready'],
        )->assertOk();

        // After transitioning to 'ready', allowed_transitions should include 'dispatched'
        $this->assertContains('dispatched', $response->json('data.allowed_transitions'));
    }

    // ── assign ────────────────────────────────────────────────────────────────

    public function test_assign_endpoint_sets_assignee(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $response = $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/assignee",
            ['assigned_to' => $staff->id],
        )->assertOk();

        $this->assertSame($staff->id, $response->json('data.assignee.id'));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'assigned_to' => $staff->id]);
    }

    public function test_assign_endpoint_unassigns_when_null(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);
        $this->orderService->assign($order, $staff, actor: $owner);

        $response = $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/assignee",
            ['assigned_to' => null],
        )->assertOk();

        $this->assertNull($response->json('data.assignee'));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'assigned_to' => null]);
    }

    public function test_assign_endpoint_returns_422_on_cross_tenant_assignee(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        // Create a user that belongs ONLY to a different tenant
        $otherTenant = Tenant::factory()->create();
        $foreignUser = User::factory()->forTenant($otherTenant, role: 'staff')->create();

        $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/assignee",
            ['assigned_to' => $foreignUser->id],
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'orders.invalid_assignment');

        // assigned_to must not have changed
        $this->assertNull($order->fresh()->assigned_to);
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_endpoint_cancels_the_order(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $response = $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/cancel",
        )->assertOk();

        $this->assertSame('cancelled', $response->json('data.status'));
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_cancel_returns_422_when_order_already_delivered(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->orderService->transitionTo($order, 'ready', $owner);
        $order = $order->fresh();
        $this->orderService->transitionTo($order, 'dispatched', $owner);
        $order = $order->fresh();
        $this->orderService->transitionTo($order, 'delivered', $owner);

        $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/orders/{$order->id}/cancel",
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'orders.cannot_cancel');

        // Status must remain 'delivered'
        $this->assertSame('delivered', $order->fresh()->status);
    }

    // ── multi-tenant boundary ─────────────────────────────────────────────────

    public function test_user_from_other_tenant_cannot_access_orders(): void
    {
        ['branch' => $branchA, 'variant' => $variantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $order = $this->createOrder($branchA, $variantA, $ownerA);

        // Tenant B user tries to view tenant A order.
        // BelongsToTenant scope hides the resource → 404.
        // If the policy fires first (implementation detail) → 403.
        // Either correctly denies cross-tenant access.
        $response = $this->tenantGetJson($tenantB, $ownerB, "/api/v1/orders/{$order->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_from_other_tenant_cannot_transition_orders(): void
    {
        ['branch' => $branchA, 'variant' => $variantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $order = $this->createOrder($branchA, $variantA, $ownerA);

        // Cross-tenant transition attempt must be denied (403 or 404).
        $response = $this->tenantPatchJson(
            $tenantB, $ownerB,
            "/api/v1/orders/{$order->id}/status",
            ['status' => 'ready'],
        );
        $this->assertContains($response->status(), [403, 404]);
    }

    // ── response structure ────────────────────────────────────────────────────

    public function test_index_response_includes_correct_envelope(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantGetJson($tenant, $owner, '/api/v1/orders')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id', 'request_id'],
                'status_counts',
            ]);
    }

    public function test_show_response_includes_allowed_transitions(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/orders/{$order->id}")
            ->assertOk();

        // 'preparing' → allowed: ['ready', 'cancelled']
        $allowed = $response->json('data.allowed_transitions');
        $this->assertContains('ready', $allowed);
        $this->assertContains('cancelled', $allowed);
    }

    public function test_show_with_customer_returns_customer_data(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $customer = Customer::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);
        $order = $this->orderService->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $owner,
            customer: $customer,
        );

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/orders/{$order->id}")
            ->assertOk();

        $this->assertSame($customer->id, $response->json('data.customer.id'));
        $this->assertSame($customer->name, $response->json('data.customer.name'));
    }
}
