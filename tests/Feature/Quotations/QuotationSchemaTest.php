<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationItem;
use App\Modules\Quotations\Models\QuotationStatusHistory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Schema + model retrofit tests for Quotations (S7-E1).
 *
 * Verifies the retrofitted schema fixes the legacy bug (missing tenant_id), that
 * BelongsToTenant scoping works, relations resolve, money is stored in centavos,
 * and the tenant quotation config columns exist with correct defaults.
 */
final class QuotationSchemaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner  = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    public function test_quotation_persists_with_tenant_id_and_correct_defaults(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()
            ->forTenant($tenant)
            ->create([
                'subtotal_cents' => 50000,
                'total_cents'    => 50000,
            ]);

        // ->fresh() is required: factory create() does not hydrate DB column defaults
        // (e.g. status='draft', tax_rate_bps=0) onto the in-memory model instance.
        $fresh = $quotation->fresh();

        $this->assertSame($tenant->id, $fresh->tenant_id);
        $this->assertSame('draft', $fresh->status);
        $this->assertSame(0, $fresh->discount_cents);
        $this->assertSame(0, $fresh->tax_rate_bps);
        $this->assertSame(0, $fresh->tax_cents);
        $this->assertSame(50000, $fresh->subtotal_cents);
        $this->assertSame(50000, $fresh->total_cents);
    }

    public function test_belongs_to_tenant_scope_filters_quotations(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();
        Quotation::factory()->forBranch($branchA)->count(2)->create();

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();
        app()->instance('currentTenant', $tenantB);
        Quotation::factory()->forBranch($branchB)->create();

        // Current tenant is B — only B's quotation is visible through the scope.
        $this->assertSame(1, Quotation::count());

        app()->instance('currentTenant', $tenantA);
        $this->assertSame(2, Quotation::count());
    }

    public function test_relations_resolve(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $customer = Customer::factory()->forTenant($tenant)->create();

        $quotation = Quotation::factory()
            ->forBranch($branch)
            ->forCustomer($customer)
            ->assignedTo($owner)
            ->createdBy($owner)
            ->create();

        $item1 = QuotationItem::factory()->forQuotation($quotation)->atPosition(0)->create();
        $item2 = QuotationItem::factory()->forQuotation($quotation)->atPosition(1)->create();

        $quotation->refresh();

        $this->assertCount(2, $quotation->items);
        $this->assertTrue($quotation->customer->is($customer));
        $this->assertTrue($quotation->branch->is($branch));
        $this->assertTrue($quotation->assignee->is($owner));
        $this->assertTrue($quotation->creator->is($owner));
        $this->assertNull($quotation->convertedOrder);

        // Items must come back ordered by sort_order.
        $this->assertSame($item1->id, $quotation->items->first()->id);
        $this->assertSame($item2->id, $quotation->items->last()->id);
    }

    public function test_items_are_ordered_by_sort_order(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create();

        QuotationItem::factory()->forQuotation($quotation)->atPosition(2)->create();
        QuotationItem::factory()->forQuotation($quotation)->atPosition(0)->create();
        QuotationItem::factory()->forQuotation($quotation)->atPosition(1)->create();

        $positions = $quotation->items()->pluck('sort_order')->toArray();

        $this->assertSame([0, 1, 2], $positions);
    }

    public function test_converted_order_relation_resolves(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();

        $order = Order::factory()->forBranch($branch)->create();
        $quotation = Quotation::factory()
            ->forTenant($tenant)
            ->create([
                'converted_order_id' => $order->id,
                'status'             => 'accepted',
            ]);

        $this->assertTrue($quotation->convertedOrder->is($order));
    }

    public function test_status_history_relation_resolves(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create();

        QuotationStatusHistory::factory()
            ->forQuotation($quotation)
            ->initial()
            ->create();

        QuotationStatusHistory::factory()
            ->forQuotation($quotation)
            ->from('draft')
            ->to('sent')
            ->create();

        $quotation->refresh();

        $this->assertCount(2, $quotation->statusHistory);
    }

    public function test_quotation_items_are_tenant_scoped(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();
        $quotationA = Quotation::factory()->forBranch($branchA)->create();
        QuotationItem::factory()->forQuotation($quotationA)->create();

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();
        app()->instance('currentTenant', $tenantB);
        $quotationB = Quotation::factory()->forBranch($branchB)->create();
        QuotationItem::factory()->forQuotation($quotationB)->create();

        // Current tenant is B — only B's item is visible.
        $this->assertSame(1, QuotationItem::count());
    }

    public function test_quotation_status_history_is_tenant_scoped(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA] = $this->setupTenant();
        $quotationA = Quotation::factory()->forBranch($branchA)->create();
        QuotationStatusHistory::factory()->forQuotation($quotationA)->initial()->create();

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();
        app()->instance('currentTenant', $tenantB);
        $quotationB = Quotation::factory()->forBranch($branchB)->create();
        QuotationStatusHistory::factory()->forQuotation($quotationB)->initial()->create();

        // Current tenant is B — only B's history row is visible.
        $this->assertSame(1, QuotationStatusHistory::count());
    }

    public function test_money_columns_are_integer_centavos(): void
    {
        $this->setupTenant();

        $quotation = Quotation::factory()->create([
            'subtotal_cents'  => 12345,
            'discount_cents'  => 1000,
            'tax_cents'       => 1500,
            'total_cents'     => 12845,
            'tax_rate_bps'    => 1300,
        ]);

        $this->assertIsInt($quotation->subtotal_cents);
        $this->assertIsInt($quotation->discount_cents);
        $this->assertIsInt($quotation->tax_cents);
        $this->assertIsInt($quotation->total_cents);
        $this->assertIsInt($quotation->tax_rate_bps);
    }

    public function test_item_money_columns_are_integer_centavos(): void
    {
        $this->setupTenant();

        $quotation = Quotation::factory()->create();
        $item = QuotationItem::factory()->forQuotation($quotation)->create([
            'unit_price_cents' => 5000,
            'line_total_cents' => 10000,
            'quantity'         => 2,
        ]);

        $this->assertIsInt($item->unit_price_cents);
        $this->assertIsInt($item->line_total_cents);
        $this->assertIsInt($item->quantity);
    }

    public function test_is_draft_returns_true_for_draft_status(): void
    {
        $this->setupTenant();

        $draft = Quotation::factory()->create(['status' => 'draft']);
        $sent  = Quotation::factory()->create(['status' => 'sent']);

        $this->assertTrue($draft->isDraft());
        $this->assertFalse($sent->isDraft());
    }

    public function test_tenant_has_quotation_config_with_defaults(): void
    {
        // ->fresh() is required: factory create() does not hydrate DB column defaults
        // onto the in-memory model instance.
        $tenant = Tenant::factory()->create()->fresh();

        $this->assertSame(0, $tenant->quotation_tax_rate_bps);
        $this->assertSame(15, $tenant->quotation_valid_days);
        $this->assertNull($tenant->quotation_terms);
    }

    public function test_quotation_config_can_be_updated_on_tenant(): void
    {
        $tenant = Tenant::factory()->create();

        $tenant->update([
            'quotation_tax_rate_bps' => 1300,
            'quotation_valid_days'   => 30,
            'quotation_terms'        => 'Payment due within 30 days.',
        ]);

        $tenant->refresh();

        $this->assertSame(1300, $tenant->quotation_tax_rate_bps);
        $this->assertSame(30, $tenant->quotation_valid_days);
        $this->assertSame('Payment due within 30 days.', $tenant->quotation_terms);
    }

    public function test_status_history_has_no_updated_at(): void
    {
        $this->setupTenant();

        $quotation = Quotation::factory()->create();
        $history   = QuotationStatusHistory::factory()
            ->forQuotation($quotation)
            ->initial()
            ->create();

        // UPDATED_AT = null means the model never writes updated_at.
        $this->assertNull($history->updated_at);
        $this->assertNotNull($history->created_at);
    }
}
