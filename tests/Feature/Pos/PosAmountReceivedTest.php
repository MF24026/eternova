<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * POS "recibí + vuelto" (cash tendered + change) feature tests.
 *
 * Tax ships OFF by default, so a 2 x 1500 sale totals 3000 cents deterministically.
 */
final class PosAmountReceivedTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, branch: Branch, variant: ProductVariant, user: User}
     */
    private function setupContext(int $stockQuantity = 10): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create([
            'base_price_cents' => 2000,
            'is_active' => true,
        ]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(1500)->create();
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app(InventoryService::class)->recordEntry($branch, $variant, $stockQuantity, $user);
        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'variant', 'user');
    }

    private function checkout(Tenant $tenant, User $user, array $payload): TestResponse
    {
        return $this->actingAs($user)->postJson($this->tenantUrl($tenant, 'api/v1/pos/checkout'), $payload);
    }

    public function test_cash_sale_stores_amount_received_and_receipt_shows_change(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user] = $this->setupContext();

        $response = $this->checkout($tenant, $user, [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'amount_received_cents' => 5000,
        ])->assertStatus(201);

        $orderId = $response->json('data.id');
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->assertSame(3000, $order->total_cents);
        $this->assertSame(5000, $order->amount_received_cents);

        // Receipt derives change = received - total.
        $receipt = $this->actingAs($user)
            ->getJson($this->tenantUrl($tenant, "api/v1/pos/orders/{$orderId}/receipt"))
            ->assertOk();

        $this->assertSame(5000, $receipt->json('data.amount_received_cents'));
        $this->assertSame(2000, $receipt->json('data.change_cents'));
    }

    public function test_card_sale_ignores_amount_received(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user] = $this->setupContext();

        $response = $this->checkout($tenant, $user, [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
            'payment_method' => 'card',
            'amount_received_cents' => 5000,
        ])->assertStatus(201);

        $order = Order::withoutGlobalScopes()->findOrFail($response->json('data.id'));
        $this->assertNull($order->amount_received_cents);
    }

    public function test_cash_underpayment_is_rejected(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user] = $this->setupContext();

        $this->checkout($tenant, $user, [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'amount_received_cents' => 1000,
        ])->assertStatus(422);

        // No order was created (transaction rolled back).
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }
}
