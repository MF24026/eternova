<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Feature tests for OrderService.
 *
 * Every test creates its own tenant/branch/variant setup so tests remain
 * independent — no shared class-level state that could cause cross-test pollution.
 *
 * Concurrency note: true parallel-thread tests are not achievable in a single
 * PHPUnit process. The "concurrent sales" test verifies the sequential equivalent:
 * after the last unit is sold, a second sale attempt throws DomainException.
 * The correctness of the FOR UPDATE lock at the DB level is proven by
 * InventoryConcurrencyTest — we rely on that contract here.
 */
final class OrderServiceTest extends TestCase
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
     * Build a minimal isolated tenant context: tenant, branch, product, variant.
     * Seeds branch_inventory with the given stock quantity.
     *
     * @return array{tenant: Tenant, branch: Branch, product: Product, variant: ProductVariant, user: User}
     */
    private function setupTenantContext(int $stockQuantity = 10): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create([
            'base_price_cents' => 2000,
        ]);
        $variant = ProductVariant::factory()
            ->forProduct($product)
            ->withPrice(1500)
            ->create();

        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        // Seed inventory
        $this->inventoryService->recordEntry($branch, $variant, $stockQuantity, $user);

        // Keep tenant resolved for subsequent model operations
        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'product', 'variant', 'user');
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_create_from_pos_creates_order_with_items_and_deducts_inventory(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext(stockQuantity: 10);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 2]],
            paymentMethod: 'cash',
            user: $user,
        );

        // Order row created
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'payment_status' => 'paid',
            'source' => 'pos',
            'status' => 'preparing',
        ]);

        // One order item
        $this->assertSame(1, $order->items->count());

        $item = $order->items->first();
        $this->assertSame($variant->id, $item->product_variant_id);
        $this->assertSame(2, $item->quantity);
        $this->assertSame(1500, $item->unit_price_cents);
        $this->assertSame(3000, $item->total_cents);

        // Inventory decreased by 2
        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();

        $this->assertSame(8, $inventory->quantity);

        // An exit movement exists with Order reference
        $this->assertDatabaseHas('inventory_movements', [
            'branch_id' => $branch->id,
            'product_variant_id' => $variant->id,
            'type' => InventoryMovement::TYPE_EXIT,
            'reference_type' => 'Order',
            'reference_id' => $order->id,
        ]);
    }

    public function test_order_total_is_subtotal_plus_tax_minus_discount(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext(stockQuantity: 5);

        // Enable IVA for this tenant so this test exercises the tax math. The
        // coded default is off-until-opt-in, so we opt in explicitly here.
        \App\Modules\Settings\Models\BranchSetting::writeDefault('tax', [
            'enabled'            => true,
            'rate_bps'           => 1300,
            'prices_include_tax' => false,
        ]);

        // Two items of the same variant, price 1500 each
        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 3]],
            paymentMethod: 'card',
        );

        // 3 × 1500 = 4500 subtotal; enabled tax is 13% (1300 bps) exclusive.
        // floor(4500 × 1300 / 10_000) = 585 tax; 4500 + 585 = 5085 total.
        $this->assertSame(4500, $order->subtotal_cents);
        $this->assertSame(585, $order->tax_cents);
        $this->assertSame(0, $order->discount_cents);
        $this->assertSame(5085, $order->total_cents);
    }

    public function test_walk_in_sale_has_null_customer(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext();

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            customer: null,
        );

        $this->assertNull($order->customer_id);
    }

    public function test_sale_records_customer_when_provided(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant]
            = $this->setupTenantContext();

        $customer = Customer::factory()->forTenant($tenant)->create();

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'transfer',
            customer: $customer,
        );

        $this->assertSame($customer->id, $order->customer_id);
    }

    public function test_product_snapshot_captures_name_and_options_at_sale_time(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'product' => $product, 'variant' => $variant]
            = $this->setupTenantContext();

        $originalName = $product->name;

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
        );

        // Mutate the product name AFTER the sale
        $product->update(['name' => 'Changed Product Name']);

        // Reload the order item fresh from DB
        $item = OrderItem::find($order->items->first()->id);

        // Snapshot must still carry the name at time of sale
        $this->assertSame($originalName, $item->product_snapshot['name']);
        $this->assertSame($variant->sku, $item->product_snapshot['sku']);
    }

    // ── Order number ──────────────────────────────────────────────────────────

    public function test_order_number_is_unique_per_tenant(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant]
            = $this->setupTenantContext(stockQuantity: 50);

        $numbers = [];
        for ($i = 0; $i < 5; $i++) {
            $order = $this->service->createFromPos(
                branch: $branch,
                items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
                paymentMethod: 'cash',
            );
            $numbers[] = $order->order_number;
        }

        // All 5 order numbers are distinct
        $this->assertSame(5, count(array_unique($numbers)));

        // Each follows the format CC-{year}-{seq}
        $year = date('Y');
        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^CC-'.$year.'-\d{4,}$/', $number);
        }
    }

    public function test_order_numbers_are_sequential_within_a_tenant(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext(stockQuantity: 20);

        $orders = [];
        for ($i = 0; $i < 3; $i++) {
            $orders[] = $this->service->createFromPos(
                branch: $branch,
                items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
                paymentMethod: 'cash',
            );
        }

        // Extract numeric sequences from CC-2026-0001, CC-2026-0002, etc.
        $sequences = array_map(static function (Order $order): int {
            return (int) substr($order->order_number, strrpos($order->order_number, '-') + 1);
        }, $orders);

        // Must be strictly ascending with no gaps
        $this->assertSame([1, 2, 3], $sequences);
    }

    /**
     * Simulates two concurrent POS sales of the last available unit.
     *
     * True parallelism is not achievable in a single PHPUnit process. This test
     * verifies the sequential equivalent: after the first sale takes the last
     * unit, the second sale must throw DomainException — no oversell.
     *
     * The correctness under true concurrency relies on lockForUpdate() inside
     * InventoryService::recordExit(), which is proven in InventoryConcurrencyTest.
     */
    public function test_concurrent_sales_of_last_unit_do_not_oversell(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext(stockQuantity: 1);

        // First sale takes the last unit
        $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
        );

        // Second sale must fail
        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
        );
    }

    // ── Rollback ──────────────────────────────────────────────────────────────

    public function test_insufficient_stock_rolls_back_entire_order(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext(stockQuantity: 2);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 5]],
            paymentMethod: 'cash',
        );

        // No order created
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        // Stock unchanged
        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();

        $this->assertSame(2, $inventory->quantity);
    }

    public function test_second_item_failure_rolls_back_first_item_and_order(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenantContext(stockQuantity: 5);

        // Create a second variant with NO stock
        $product2 = Product::factory()->forTenant($tenant)->create();
        $variantNoStock = ProductVariant::factory()
            ->forProduct($product2)
            ->withPrice(500)
            ->create();
        // No recordEntry for variantNoStock — quantity defaults to 0

        app()->instance('currentTenant', $tenant);

        // Access first variant (created in setupTenantContext)
        $variant1 = ProductVariant::withoutGlobalScope(TenantScope::class)
            ->whereHas('product', fn ($q) => $q->withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id))
            ->where('id', '!=', $variantNoStock->id)
            ->first();

        $this->expectException(DomainException::class);

        $this->service->createFromPos(
            branch: $branch,
            items: [
                ['product_variant_id' => $variant1->id, 'quantity' => 1],
                ['product_variant_id' => $variantNoStock->id, 'quantity' => 1],
            ],
            paymentMethod: 'cash',
        );

        // Neither order nor items should exist — full rollback
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        // First variant stock unchanged
        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant1->id)
            ->first();

        if ($inventory !== null) {
            // If a BranchInventory row exists (from the entry), it should still have 5
            $this->assertSame(5, $inventory->quantity);
        }
    }

    // ── Tenant isolation ──────────────────────────────────────────────────────

    public function test_order_is_tenant_isolated(): void
    {
        // Tenant A creates an order
        ['tenant' => $tenantA, 'branch' => $branchA, 'variant' => $variantA]
            = $this->setupTenantContext(stockQuantity: 5);

        $orderA = $this->service->createFromPos(
            branch: $branchA,
            items: [['product_variant_id' => $variantA->id, 'quantity' => 1]],
            paymentMethod: 'cash',
        );

        // Switch to Tenant B context
        ['tenant' => $tenantB] = $this->setupTenantContext(stockQuantity: 5);
        app()->instance('currentTenant', $tenantB);

        // Tenant B should not see Tenant A's order via the BelongsToTenant scope
        $found = Order::find($orderA->id);
        $this->assertNull($found, 'Tenant B must not see Tenant A orders through tenant scope');
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_creating_order_with_no_items_throws_invalid_argument(): void
    {
        ['branch' => $branch] = $this->setupTenantContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no items/');

        $this->service->createFromPos(
            branch: $branch,
            items: [],
            paymentMethod: 'cash',
        );
    }

    public function test_creating_order_with_zero_quantity_throws_invalid_argument(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/greater than zero/');

        $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 0]],
            paymentMethod: 'cash',
        );
    }

    // ── Cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_sets_status_to_cancelled(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext();

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $user,
        );

        $this->service->cancel($order, $user);

        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_cancel_does_not_restock_inventory(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext(stockQuantity: 10);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 3]],
            paymentMethod: 'cash',
        );

        $this->service->cancel($order);

        // Stock remains at 7 (10 - 3), not restored to 10
        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant->id)
            ->firstOrFail();

        $this->assertSame(7, $inventory->quantity);
    }

    public function test_cancelling_an_already_cancelled_order_throws_domain_exception(): void
    {
        ['branch' => $branch, 'variant' => $variant] = $this->setupTenantContext();

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
        );

        $this->service->cancel($order);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/already cancelled/');

        $this->service->cancel($order->fresh());
    }
}
