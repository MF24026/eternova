<?php

declare(strict_types=1);

namespace Tests\Feature\Pos;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Customers\Models\Customer;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * POS checkout feature tests.
 *
 * Each test sets up its own isolated tenant context. No shared state across tests.
 *
 * The `app()->instance('currentTenant', $tenant)` binding is required so that
 * BelongsToTenant scopes, User::currentRole(), and Gate abilities resolve
 * the correct tenant within the test process. For HTTP tests using ActingAsTenantMember,
 * the EnsureTenant middleware sets this via the subdomain Host header.
 */
final class PosCheckoutTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventoryService = app(InventoryService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal tenant context for checkout tests.
     *
     * @return array{tenant: Tenant, branch: Branch, product: Product, variant: ProductVariant, user: User}
     */
    private function setupTenantContext(int $stockQuantity = 10): array
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

        $this->inventoryService->recordEntry($branch, $variant, $stockQuantity, $user);

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'product', 'variant', 'user');
    }

    private function checkoutUrl(Tenant $tenant): string
    {
        return $this->tenantUrl($tenant, 'api/v1/pos/checkout');
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_checkout_creates_order_and_deducts_stock(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext(stockQuantity: 5);

        $response = $this->actingAs($user)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $branch->id,
                'items' => [
                    ['product_variant_id' => $variant->id, 'quantity' => 2],
                ],
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.source', 'pos')
            ->assertJsonStructure(['data' => [
                'id', 'order_number', 'status', 'total_cents', 'items',
            ]]);

        // Order persisted
        $this->assertDatabaseHas('orders', [
            'tenant_id' => $tenant->id,
            'branch_id' => $branch->id,
            'payment_status' => 'paid',
            'source' => 'pos',
        ]);

        // Stock deducted: 5 - 2 = 3
        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertSame(3, $inventory->quantity);

        // Response contains items
        $responseData = $response->json('data');
        $this->assertNotEmpty($responseData['items']);
        $this->assertNotEmpty($responseData['order_number']);
    }

    public function test_checkout_with_insufficient_stock_returns_422(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext(stockQuantity: 1);

        $orderCountBefore = Order::withoutGlobalScope(TenantScope::class)->count();

        $response = $this->actingAs($user)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $branch->id,
                'items' => [
                    ['product_variant_id' => $variant->id, 'quantity' => 5],
                ],
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'pos.checkout_failed');

        // No order created
        $this->assertSame(
            $orderCountBefore,
            Order::withoutGlobalScope(TenantScope::class)->count()
        );

        // Stock unchanged
        $inventory = BranchInventory::withoutGlobalScope(TenantScope::class)
            ->where('branch_id', $branch->id)
            ->where('product_variant_id', $variant->id)
            ->first();

        $this->assertNotNull($inventory);
        $this->assertSame(1, $inventory->quantity);
    }

    public function test_checkout_records_walk_in_when_no_customer_id_given(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext();

        $response = $this->actingAs($user)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $branch->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
                'payment_method' => 'card',
            ]);

        $response->assertStatus(201);

        // customer is null in response
        $this->assertNull($response->json('data.customer'));

        // order row has customer_id null
        $orderId = $response->json('data.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'customer_id' => null,
        ]);
    }

    public function test_checkout_records_registered_customer(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext();

        $customer = Customer::factory()->forTenant($tenant)->create();

        $response = $this->actingAs($user)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $branch->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
                'payment_method' => 'transfer',
                'customer_id' => $customer->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'customer_id' => $customer->id,
        ]);
    }

    public function test_checkout_requires_branch_of_current_tenant(): void
    {
        ['tenant' => $tenant, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext();

        // Create an isolated second tenant and branch
        $otherTenant = Tenant::factory()->create();
        $otherBranch = Branch::factory()->forTenant($otherTenant)->create();

        $response = $this->actingAs($user)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $otherBranch->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
                'payment_method' => 'cash',
            ]);

        // Branch validation fails — 422
        $response->assertStatus(422);
    }

    public function test_staff_can_checkout(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant]
            = $this->setupTenantContext();

        $staffUser = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $response = $this->actingAs($staffUser)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $branch->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(201);
    }

    public function test_unauthenticated_user_cannot_checkout(): void
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();

        $response = $this->postJson($this->checkoutUrl($tenant), [
            'branch_id' => $branch->id,
            'items' => [['product_variant_id' => 1, 'quantity' => 1]],
            'payment_method' => 'cash',
        ]);

        $response->assertStatus(401);
    }

    public function test_checkout_is_tenant_isolated(): void
    {
        // Tenant A context and user
        $tenantA = Tenant::factory()->create();
        $branchA = Branch::factory()->forTenant($tenantA)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenantA);
        $userA = User::factory()->forTenant($tenantA, role: 'staff')->create();
        $productA = Product::factory()->forTenant($tenantA)->create(['is_active' => true]);
        $variantA = ProductVariant::factory()->forProduct($productA)->withPrice(1000)->create();
        $this->inventoryService->recordEntry($branchA, $variantA, 10, $userA);

        // Tenant B context — creates a variant in a different tenant
        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenantB);
        $productB = Product::factory()->forTenant($tenantB)->create(['is_active' => true]);
        $variantB = ProductVariant::factory()->forProduct($productB)->withPrice(1000)->create();
        $userB = User::factory()->forTenant($tenantB, role: 'staff')->create();
        $this->inventoryService->recordEntry($branchB, $variantB, 10, $userB);

        // Switch back to tenant A context
        app()->instance('currentTenant', $tenantA);

        // Tenant A user attempts to buy a variant from tenant B
        $response = $this->actingAs($userA)
            ->postJson($this->checkoutUrl($tenantA), [
                'branch_id' => $branchA->id,
                'items' => [['product_variant_id' => $variantB->id, 'quantity' => 1]],
                'payment_method' => 'cash',
            ]);

        // OrderService rejects the variant as it does not belong to tenant A
        $response->assertStatus(422);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_checkout_rejects_empty_items_array(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'user' => $user] = $this->setupTenantContext();

        $response = $this->actingAs($user)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $branch->id,
                'items' => [],
                'payment_method' => 'cash',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('items');
    }

    public function test_checkout_rejects_invalid_payment_method(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'variant' => $variant, 'user' => $user]
            = $this->setupTenantContext();

        $response = $this->actingAs($user)
            ->postJson($this->checkoutUrl($tenant), [
                'branch_id' => $branch->id,
                'items' => [['product_variant_id' => $variant->id, 'quantity' => 1]],
                'payment_method' => 'bitcoin',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('payment_method');
    }
}
