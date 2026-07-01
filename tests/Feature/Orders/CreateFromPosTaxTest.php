<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Settings\Models\BranchSetting;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies that createFromPos computes and persists IVA from branch tax settings.
 *
 * Three coverage cases: exclusive tax (added on top), inclusive tax (extracted
 * from price), and disabled tax (zero everywhere). All arithmetic is verified
 * against TaxCalculator's documented rounding rules (floor for exclusive,
 * round-half-up extract for inclusive).
 */
final class CreateFromPosTaxTest extends TestCase
{
    use RefreshDatabase;

    private OrderService $service;

    private InventoryService $inventoryService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service          = app(OrderService::class);
        $this->inventoryService = app(InventoryService::class);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Build a minimal isolated tenant context with a variant at the given price.
     * Seeds branch inventory with 10 units and binds currentTenant.
     *
     * @return array{0: Branch, 1: ProductVariant}
     */
    private function posFixture(int $unitPriceCents): array
    {
        $tenant  = Tenant::factory()->create();
        $branch  = Branch::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);

        $product = Product::factory()->forTenant($tenant)->create([
            'base_price_cents' => $unitPriceCents,
        ]);
        $variant = ProductVariant::factory()
            ->forProduct($product)
            ->withPrice($unitPriceCents)
            ->create();

        $user = \App\Models\User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->inventoryService->recordEntry($branch, $variant, 10, $user);

        // Re-bind after recordEntry (service may reset the scope internally)
        app()->instance('currentTenant', $tenant);

        return [$branch, $variant];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_pos_order_applies_exclusive_tax_from_branch_settings(): void
    {
        [$branch, $variant] = $this->posFixture(unitPriceCents: 10_000);
        BranchSetting::writeDefault('tax', [
            'enabled'            => true,
            'rate_bps'           => 1300,
            'prices_include_tax' => false,
        ]);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            customer: null,
            user: null,
        );

        // Exclusive: tax is added on top of the subtotal.
        // floor(10_000 * 1300 / 10_000) = 1_300
        $this->assertSame(10_000, $order->subtotal_cents);
        $this->assertSame(1_300, $order->tax_cents);
        $this->assertSame(11_300, $order->total_cents);
        $this->assertSame(1300, $order->tax_rate_bps);
    }

    public function test_pos_order_breaks_out_inclusive_tax_without_changing_total(): void
    {
        [$branch, $variant] = $this->posFixture(unitPriceCents: 11_300);
        BranchSetting::writeDefault('tax', [
            'enabled'            => true,
            'rate_bps'           => 1300,
            'prices_include_tax' => true,
        ]);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            customer: null,
            user: null,
        );

        // Inclusive: tax is extracted from price; total stays 11_300.
        // net = intdiv(11_300 * 10_000 + intdiv(11_300, 2), 11_300) = 10_000
        // tax = 11_300 - 10_000 = 1_300
        $this->assertSame(10_000, $order->subtotal_cents);
        $this->assertSame(1_300, $order->tax_cents);
        $this->assertSame(11_300, $order->total_cents);
        $this->assertSame(1300, $order->tax_rate_bps);
    }

    public function test_pos_order_has_zero_tax_when_disabled(): void
    {
        [$branch, $variant] = $this->posFixture(unitPriceCents: 10_000);
        BranchSetting::writeDefault('tax', [
            'enabled'  => false,
            'rate_bps' => 1300,
        ]);

        $order = $this->service->createFromPos(
            branch: $branch,
            items: [['product_variant_id' => $variant->id, 'quantity' => 1]],
            paymentMethod: 'cash',
            customer: null,
            user: null,
        );

        $this->assertSame(0, $order->tax_cents);
        $this->assertSame(10_000, $order->total_cents);
        $this->assertSame(0, $order->tax_rate_bps);
    }
}
