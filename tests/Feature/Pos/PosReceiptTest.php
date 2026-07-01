<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * POS receipt endpoint tests.
 *
 * The receipt endpoint loads the full order tree (branch.tenant, customer, user, items)
 * and returns a receipt-shaped payload suitable for thermal/screen rendering.
 */
final class PosReceiptTest extends TestCase
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
     * Creates an order via OrderService (real checkout, real deduction) and
     * returns the created order along with the tenant + user context.
     *
     * @return array{tenant: Tenant, branch: Branch, user: User, orderId: string}
     */
    private function setupOrderForReceipt(): array
    {
        $tenant = Tenant::factory()->create(['business_name' => 'Flores del Valle']);
        $branch = Branch::factory()->forTenant($tenant)->create(['name' => 'Sucursal Centro']);
        app()->instance('currentTenant', $tenant);

        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        $product = Product::factory()->forTenant($tenant)->create([
            'base_price_cents' => 3000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(2500)->create();

        $this->inventoryService->recordEntry($branch, $variant, 10, $user);

        app()->instance('currentTenant', $tenant);

        $order = $this->orderService->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 2]],
            paymentMethod: 'cash',
            customer: null,
            user: $user,
        );

        return [
            'tenant' => $tenant,
            'branch' => $branch,
            'user' => $user,
            'orderId' => $order->id,
        ];
    }

    private function receiptUrl(Tenant $tenant, string $orderId): string
    {
        return $this->tenantUrl($tenant, "api/v1/pos/orders/{$orderId}/receipt");
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_receipt_returns_order_data_with_business_and_items(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user, 'orderId' => $orderId]
            = $this->setupOrderForReceipt();

        $response = $this->actingAs($user)
            ->getJson($this->receiptUrl($tenant, $orderId));

        $response->assertOk();

        $data = $response->json('data');

        // Core order fields
        $this->assertNotEmpty($data['order_number']);
        $this->assertSame('cash', $data['payment_method']);
        $this->assertSame('paid', $data['payment_status']);
        // 2500 × 2 = 5000 subtotal; coded-default tax is 13% (1300 bps) exclusive.
        // floor(5000 × 1300 / 10_000) = 650 tax; 5000 + 650 = 5650 total.
        $this->assertSame(5650, $data['total_cents']);

        // Business info from tenant
        $this->assertSame('Flores del Valle', $data['business']['name']);

        // Branch name
        $this->assertSame('Sucursal Centro', $data['branch']['name']);

        // Items present
        $this->assertNotEmpty($data['items']);
        $item = $data['items'][0];
        $this->assertSame(2, $item['quantity']);
        $this->assertSame(2500, $item['unit_price_cents']);
        $this->assertSame(5000, $item['total_cents']);

        // Totals
        $this->assertSame(5000, $data['subtotal_cents']);
        $this->assertSame(650, $data['tax_cents']);
    }

    public function test_receipt_is_tenant_isolated(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->setupOrderForReceipt();

        // Create an order for tenant B
        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();
        app()->instance('currentTenant', $tenantB);
        $productB = Product::factory()->forTenant($tenantB)->create(['is_active' => true]);
        $variantB = ProductVariant::factory()->forProduct($productB)->withPrice(1000)->create();
        $userB = User::factory()->forTenant($tenantB, role: 'staff')->create();
        $this->inventoryService->recordEntry($branchB, $variantB, 5, $userB);

        app()->instance('currentTenant', $tenantB);
        $orderB = $this->orderService->createFromPos(
            branch: $branchB,
            items: [['product_variant_id' => $variantB->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $userB,
        );

        // Restore tenant A context — user A tries to access tenant B's order
        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($user)
            ->getJson($this->receiptUrl($tenant, $orderB->id));

        // BelongsToTenant scope means tenant B's order is not found in tenant A context
        $response->assertStatus(404);
    }

    public function test_receipt_requires_auth(): void
    {
        ['tenant' => $tenant, 'orderId' => $orderId] = $this->setupOrderForReceipt();

        $response = $this->getJson($this->receiptUrl($tenant, $orderId));

        $response->assertStatus(401);
    }
}
