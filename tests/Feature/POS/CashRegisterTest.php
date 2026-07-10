<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\POS\Models\CashRegisterSession;
use App\Modules\POS\Services\CashRegisterService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Cash register (Slice 1): session lifecycle + arqueo, POS order linkage, giro gating.
 */
final class CashRegisterTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function context(string $giro = 'ropa_boutique'): array
    {
        $tenant = Tenant::factory()->create(['business_type' => $giro]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenant);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    public function test_open_creates_an_open_session_with_a_number(): void
    {
        ['branch' => $branch, 'owner' => $owner] = $this->context();

        $session = app(CashRegisterService::class)->open($branch, $owner, 10000, 'Turno manana');

        $this->assertTrue($session->isOpen());
        $this->assertSame(1, $session->session_number);
        $this->assertSame(10000, $session->opening_amount_cents);
    }

    public function test_a_second_open_session_for_the_same_cashier_and_branch_is_rejected(): void
    {
        ['branch' => $branch, 'owner' => $owner] = $this->context();
        $service = app(CashRegisterService::class);
        $service->open($branch, $owner, 10000);

        $this->expectException(\DomainException::class);
        $service->open($branch, $owner, 5000);
    }

    public function test_close_computes_expected_from_cash_sales_and_the_difference(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->context();
        $service = app(CashRegisterService::class);
        $session = $service->open($branch, $owner, 10000);

        // A cash sale of 2500 links to the session; a card sale of 4000 does not count.
        $this->cashSale($branch, $owner, 2500, 'cash');
        $this->cashSale($branch, $owner, 4000, 'card');

        // Counted 12600 -> expected 2500 (cash only) -> total expected 12500 -> +100 over.
        $closed = $service->close($session, 12600, 'Sobra 1', $owner);

        $this->assertTrue($closed->isClosed());
        $this->assertSame(2500, $closed->expected_amount_cents);
        $this->assertSame(100, $closed->difference_cents);
    }

    public function test_closing_an_already_closed_session_is_rejected(): void
    {
        ['branch' => $branch, 'owner' => $owner] = $this->context();
        $service = app(CashRegisterService::class);
        $session = $service->open($branch, $owner, 10000);
        $service->close($session, 10000, null, $owner);

        $this->expectException(\DomainException::class);
        $service->close($session->fresh(), 10000, null, $owner);
    }

    public function test_pos_cash_sale_links_to_the_open_session(): void
    {
        ['branch' => $branch, 'owner' => $owner] = $this->context();
        $session = app(CashRegisterService::class)->open($branch, $owner, 10000);

        $order = $this->cashSale($branch, $owner, 3000, 'cash');

        $this->assertSame($session->id, $order->cash_register_session_id);
        $this->assertSame(3000, $session->fresh()->cash_sales_cents);
    }

    public function test_pos_sale_without_open_session_has_no_link(): void
    {
        ['branch' => $branch, 'owner' => $owner] = $this->context();

        $order = $this->cashSale($branch, $owner, 3000, 'cash');

        $this->assertNull($order->cash_register_session_id);
    }

    public function test_cash_register_endpoints_403_when_giro_disables_the_module(): void
    {
        // floreria_regalos does NOT enable cash_register.
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->context('floreria_regalos');

        $this->actingAs($owner)
            ->getJson($this->tenantUrl($tenant, "api/v1/pos/cash-register/current?branch_id={$branch->id}"))
            ->assertStatus(403);
    }

    public function test_owner_can_open_and_close_via_the_api(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->context();

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/pos/cash-register/open'), [
                'branch_id' => $branch->id,
                'opening_amount_cents' => 10000,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'open');

        $session = CashRegisterSession::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, "api/v1/pos/cash-register/{$session->id}/close"), [
                'closing_amount_cents' => 10000,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.difference_cents', 0);
    }

    private function cashSale(Branch $branch, User $cashier, int $totalCents, string $method): Order
    {
        $product = Product::factory()->forTenant(current_tenant())->create(['base_price_cents' => $totalCents]);
        $variant = ProductVariant::factory()->forProduct($product)->create(['price_cents' => $totalCents]);
        app(InventoryService::class)->recordEntry($branch, $variant, 10, $cashier);

        return app(OrderService::class)->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: $method,
            customer: null,
            user: $cashier,
            notes: null,
            amountReceivedCents: $method === 'cash' ? $totalCents : null,
        );
    }
}
