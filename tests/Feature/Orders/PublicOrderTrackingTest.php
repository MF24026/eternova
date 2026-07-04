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
use Tests\TestCase;

/**
 * Feature tests for the public order tracking endpoint (S4-E4).
 *
 * Verifies:
 *   - Valid tracking token returns 200 with order_number, status, timeline, brand
 *   - Timeline excludes assignment-only rows (from_status === to_status)
 *     but includes the creation row (from_status IS NULL) and real transitions
 *   - Payload contains NO PII: no customer name/phone/email, no notes,
 *     no assignee, no internal user ids
 *   - Payload contains NO financial data: no *_cents fields, no totals
 *   - Payload contains NO item/product data
 *   - Invalid / unknown token → 404
 *   - Cross-tenant security: token belonging to tenant A, requested on
 *     tenant B's subdomain → 404  (BelongsToTenant scope does the work)
 *   - createFromPos() generates a unique tracking_token on each order
 *
 * Route: GET /api/v1/track/{token}  (no auth:sanctum — public)
 * Tenant resolved from subdomain by EnsureTenant middleware.
 */
final class PublicOrderTrackingTest extends TestCase
{
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
     * Build a minimal but complete tenant: branch, owner, product with stock.
     *
     * @return array{tenant: Tenant, branch: Branch, owner: User, product: Product, variant: ProductVariant}
     */
    private function setupTenant(int $stock = 20): array
    {
        $tenant = Tenant::factory()->create([
            'business_name' => 'Flores Eternova',
            'primary_color' => '#7c545d',
            'secondary_color' => '#5a4b71',
            'currency' => 'USD',
            'country_code' => 'SV',
            'language' => 'es',
        ]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create(['base_price_cents' => 2500]);
        $variant = ProductVariant::factory()->forProduct($product)->withPrice(2500)->create();

        $this->inventoryService->recordEntry($branch, $variant, $stock, $owner);

        // Re-bind because recordEntry may clear the binding in some test contexts
        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner', 'product', 'variant');
    }

    /**
     * Create an order through the service so it has a proper tracking_token
     * and an initial status history row.
     */
    private function createOrder(
        Branch $branch,
        ProductVariant $variant,
        User $user,
        ?Customer $customer = null,
        ?string $notes = null,
    ): Order {
        app()->instance('currentTenant', Tenant::find($branch->tenant_id));

        return $this->orderService->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            user: $user,
            customer: $customer,
            notes: $notes,
        );
    }

    /**
     * Build the public tracking URL for a given tenant and token.
     */
    private function trackingUrl(Tenant $tenant, string $token): string
    {
        $baseDomain = config('tenancy.base_domain', 'eternova.app');

        return "http://{$tenant->slug}.{$baseDomain}/api/v1/track/{$token}";
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_valid_token_returns_200_with_required_tracking_fields(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'order_number',
                    'status',
                    'branch_name',
                    'created_at',
                    'timeline',
                    'brand',
                ],
            ]);
    }

    public function test_valid_token_returns_correct_order_number_and_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $response = $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();

        $this->assertSame($order->order_number, $response->json('data.order_number'));
        $this->assertSame('preparing', $response->json('data.status'));
    }

    public function test_brand_block_matches_storefront_tenant_resource_shape(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $response = $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();

        $brand = $response->json('data.brand');

        $this->assertSame($tenant->slug, $brand['slug']);
        $this->assertSame($tenant->business_name, $brand['business_name']);
        $this->assertSame($tenant->primary_color, $brand['primary_color']);
        $this->assertSame($tenant->secondary_color, $brand['secondary_color']);
        $this->assertSame($tenant->currency, $brand['currency']);
        $this->assertSame($tenant->country_code, $brand['country_code']);
        $this->assertSame($tenant->language, $brand['language']);

        // These brand keys must be present (may be null if not configured by tenant)
        $this->assertArrayHasKey('logo_url', $brand);
        $this->assertArrayHasKey('favicon_url', $brand);
        $this->assertArrayHasKey('whatsapp_number', $brand);
        $this->assertArrayHasKey('tagline', $brand);
    }

    // ── Timeline filtering ────────────────────────────────────────────────────

    public function test_timeline_includes_creation_row_and_real_transitions(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->orderService->transitionTo($order, 'ready', actor: $owner);

        $response = $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();

        $timeline = $response->json('data.timeline');

        // Creation row (from_status null → 'preparing') + transition ('preparing' → 'ready')
        $this->assertCount(2, $timeline);
        $this->assertSame('preparing', $timeline[0]['status']);
        $this->assertSame('ready', $timeline[1]['status']);
        $this->assertArrayHasKey('at', $timeline[0]);
    }

    public function test_timeline_excludes_assignment_only_rows(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        // Write one assignment event (from_status === to_status — should NOT appear in timeline)
        app()->instance('currentTenant', $tenant);
        $this->orderService->assign($order, $staff, actor: $owner);

        $response = $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();

        $timeline = $response->json('data.timeline');

        // Only the creation row should be present; the assignment row is excluded
        $this->assertCount(1, $timeline);
        $this->assertSame('preparing', $timeline[0]['status']);
    }

    public function test_timeline_excludes_assignment_row_but_includes_subsequent_transition(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);
        $this->orderService->assign($order, $staff, actor: $owner);       // assignment only

        $order = $order->fresh();
        $this->orderService->transitionTo($order, 'ready', actor: $owner); // real transition

        $response = $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();

        $timeline = $response->json('data.timeline');

        // creation + real transition = 2 rows; assignment row excluded
        $this->assertCount(2, $timeline);
        $statuses = array_column($timeline, 'status');
        $this->assertContains('preparing', $statuses);
        $this->assertContains('ready', $statuses);
    }

    // ── Sanitisation — NO PII / NO prices / NO items ──────────────────────────

    public function test_payload_contains_no_pii_no_prices_no_items(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        // Create a customer WITH phone and email — must not appear in response
        $customer = Customer::factory()->forTenant($tenant)->withPhone('76543210')->create([
            'name' => 'Ana García',
            'email' => 'ana@example.com',
        ]);

        $order = $this->createOrder(
            branch: $branch,
            variant: $variant,
            user: $owner,
            customer: $customer,
            notes: 'Internal note for staff only',
        );

        // Assign a staff member — their details must not appear in response
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create(['name' => 'Carlos Staff']);
        app()->instance('currentTenant', $tenant);
        $this->orderService->assign($order, $staff, actor: $owner);

        $response = $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();

        $json = $response->json();
        $encoded = json_encode($json);

        // --- NO PII ---
        $this->assertStringNotContainsString('Ana García', (string) $encoded);
        $this->assertStringNotContainsString('ana@example.com', (string) $encoded);
        $this->assertStringNotContainsString('76543210', (string) $encoded);
        $this->assertStringNotContainsString('Carlos Staff', (string) $encoded);

        // --- NO financial data ---
        $this->assertStringNotContainsString('_cents', (string) $encoded);
        $this->assertStringNotContainsString('total', (string) $encoded);
        $this->assertStringNotContainsString('subtotal', (string) $encoded);
        $this->assertStringNotContainsString('tax', (string) $encoded);
        $this->assertStringNotContainsString('discount', (string) $encoded);
        $this->assertStringNotContainsString('payment', (string) $encoded);

        // --- NO items / products ---
        $this->assertArrayNotHasKey('items', $json['data']);
        $this->assertStringNotContainsString('product', (string) $encoded);
        $this->assertStringNotContainsString('quantity', (string) $encoded);

        // --- NO internal notes ---
        $this->assertStringNotContainsString('Internal note for staff only', (string) $encoded);
        $this->assertArrayNotHasKey('notes', $json['data']);

        // --- NO internal keys ---
        $this->assertArrayNotHasKey('id', $json['data']);
        $this->assertArrayNotHasKey('tenant_id', $json['data']);
        $this->assertArrayNotHasKey('customer', $json['data']);
        $this->assertArrayNotHasKey('assignee', $json['data']);
        $this->assertArrayNotHasKey('user_id', $json['data']);
        $this->assertArrayNotHasKey('assigned_to', $json['data']);

        // --- Tracking token is NOT echoed back ---
        $this->assertArrayNotHasKey('tracking_token', $json['data']);
        $this->assertStringNotContainsString($order->tracking_token, (string) $encoded);
    }

    // ── Timeline rows contain no staff data ───────────────────────────────────

    public function test_timeline_entries_contain_no_user_or_note_data(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);
        app()->instance('currentTenant', $tenant);
        $this->orderService->transitionTo($order, 'ready', actor: $owner, note: 'Staff internal note');

        $response = $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();

        $timeline = $response->json('data.timeline');

        foreach ($timeline as $entry) {
            $this->assertArrayHasKey('status', $entry);
            $this->assertArrayHasKey('at', $entry);
            $this->assertArrayNotHasKey('note', $entry);
            $this->assertArrayNotHasKey('user', $entry);
            $this->assertArrayNotHasKey('from_status', $entry);
            $this->assertArrayNotHasKey('user_id', $entry);
        }

        // The note must not leak into the encoded payload at all
        $encoded = json_encode($response->json());
        $this->assertStringNotContainsString('Staff internal note', (string) $encoded);
    }

    // ── 404 cases ─────────────────────────────────────────────────────────────

    public function test_unknown_token_returns_404(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->trackingUrl($tenant, 'thisisatotallyinvalidtoken00000001'))
            ->assertNotFound();
    }

    public function test_empty_token_segment_returns_404(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        // Route regex [A-Za-z0-9]+ will not match empty segment — results in 404
        $baseDomain = config('tenancy.base_domain', 'eternova.app');
        $this->getJson("http://{$tenant->slug}.{$baseDomain}/api/v1/track/")
            ->assertNotFound();
    }

    // ── Cross-tenant security ─────────────────────────────────────────────────

    /**
     * This is the security crux of S4-E4.
     *
     * Token from tenant A, requested on tenant B's subdomain, MUST return 404.
     * BelongsToTenant global scope (activated by EnsureTenant middleware) filters
     * the Order::where('tracking_token', $token) query to the current tenant
     * automatically — so tenant A's order is invisible to tenant B's context.
     */
    public function test_token_from_tenant_a_on_tenant_b_subdomain_returns_404(): void
    {
        // Set up tenant A and create an order with a known token
        ['tenant' => $tenantA, 'branch' => $branchA, 'owner' => $ownerA, 'variant' => $variantA]
            = $this->setupTenant();

        $orderA = $this->createOrder($branchA, $variantA, $ownerA);
        $tokenA = $orderA->tracking_token;
        $this->assertNotNull($tokenA);

        // Set up tenant B (independent; no shared resources)
        $tenantB = Tenant::factory()->create();

        // Request tenant A's token via tenant B's subdomain → must 404
        $this->getJson($this->trackingUrl($tenantB, $tokenA))
            ->assertNotFound();
    }

    public function test_token_from_tenant_b_is_not_reachable_from_tenant_a(): void
    {
        ['tenant' => $tenantA] = $this->setupTenant();

        ['tenant' => $tenantB, 'branch' => $branchB, 'owner' => $ownerB, 'variant' => $variantB]
            = $this->setupTenant();

        $orderB = $this->createOrder($branchB, $variantB, $ownerB);
        $tokenB = $orderB->tracking_token;

        // Confirm it works on tenant B's own subdomain (sanity)
        $this->getJson($this->trackingUrl($tenantB, $tokenB))
            ->assertOk();

        // Must fail on tenant A's subdomain
        $this->getJson($this->trackingUrl($tenantA, $tokenB))
            ->assertNotFound();
    }

    // ── Token uniqueness ──────────────────────────────────────────────────────

    public function test_create_from_pos_generates_a_unique_tracking_token_per_order(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant(stock: 50);

        app()->instance('currentTenant', $tenant);
        $orderOne = $this->createOrder($branch, $variant, $owner);
        app()->instance('currentTenant', $tenant);
        $orderTwo = $this->createOrder($branch, $variant, $owner);
        app()->instance('currentTenant', $tenant);
        $orderThree = $this->createOrder($branch, $variant, $owner);

        $this->assertNotNull($orderOne->tracking_token);
        $this->assertNotNull($orderTwo->tracking_token);
        $this->assertNotNull($orderThree->tracking_token);

        $tokens = [
            $orderOne->tracking_token,
            $orderTwo->tracking_token,
            $orderThree->tracking_token,
        ];

        // All three tokens are distinct
        $this->assertSame(3, count(array_unique($tokens)));
    }

    public function test_tracking_token_is_32_chars_of_alphanumeric(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        $this->assertNotNull($order->tracking_token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $order->tracking_token);
    }

    // ── Endpoint is reachable without authentication ───────────────────────────

    public function test_public_tracking_endpoint_does_not_require_authentication(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner, 'variant' => $variant]
            = $this->setupTenant();

        $order = $this->createOrder($branch, $variant, $owner);

        // Note: no actingAs() call — purely unauthenticated request
        $this->getJson($this->trackingUrl($tenant, $order->tracking_token))
            ->assertOk();
    }
}
