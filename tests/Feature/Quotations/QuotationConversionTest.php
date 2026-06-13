<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Quotations\Jobs\ExpireQuotationsJob;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationStatusHistory;
use App\Modules\Quotations\Services\QuotationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for S7-E5: quotation → order conversion and automatic expiry.
 *
 * Covers:
 *   - accept(convertToOrder: true) creates an Order with the correct shape
 *   - No double-conversion (idempotency guard)
 *   - accept(convertToOrder: false) is still a plain transition
 *   - Branch resolution: explicit branch vs. tenant main branch vs. neither
 *   - ExpireQuotationsJob: marks expired, skips non-expirable, multi-tenant isolation
 *   - API: POST /quotations/{id}/accept with convert_to_order: true
 */
final class QuotationConversionTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(QuotationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create([
            'quotation_tax_rate_bps' => 0,
            'quotation_valid_days'   => 15,
        ]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner  = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    /**
     * Build a draft quotation via the service (has a real sequence number and history).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createDraftQuotation(array $overrides = []): Quotation
    {
        return $this->service->create(array_merge([
            'issue_date' => now()->toDateString(),
            'items'      => [
                ['description' => 'Arreglo floral', 'quantity' => 2,  'unit_price_cents' => 5000, 'sort_order' => 0],
                ['description' => 'Delivery',        'quantity' => 1,  'unit_price_cents' => 1500, 'sort_order' => 1],
            ],
            'discount_cents' => 0,
            'tax_rate_bps'   => 0,
        ], $overrides));
    }

    // ── Conversion: happy path ────────────────────────────────────────────────

    public function test_accept_with_convert_creates_order_with_correct_shape(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = $this->createDraftQuotation();
        // subtotal: 2*5000 + 1*1500 = 11500; tax=0; total=11500

        $accepted = $this->service->accept(
            quotation: $quotation,
            convertToOrder: true,
        );

        $this->assertSame('accepted', $accepted->status);
        $this->assertNotNull($accepted->converted_order_id);

        $order = Order::withoutGlobalScopes()->find($accepted->converted_order_id);
        $this->assertNotNull($order);

        // source must be 'quotation'
        $this->assertSame('quotation', $order->source);

        // Status: pending (fresh work — not delivered like reservations)
        $this->assertSame('pending', $order->status);

        // Payment status: pending (nothing paid on a quote)
        $this->assertSame('pending', $order->payment_status);

        // Cents breakdown must match the quotation exactly
        $this->assertSame(11500, $order->subtotal_cents);
        $this->assertSame(0, $order->tax_cents);
        $this->assertSame(0, $order->discount_cents);
        $this->assertSame(11500, $order->total_cents);

        // Correct tenant
        $this->assertSame($tenant->id, $order->tenant_id);
    }

    public function test_accept_with_convert_sets_converted_order_id_on_quotation(): void
    {
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();
        $this->assertNull($quotation->converted_order_id);

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $this->assertNotNull($accepted->converted_order_id);
        $this->assertSame($accepted->converted_order_id, $quotation->fresh()->converted_order_id);
    }

    public function test_accept_with_convert_carries_customer_to_order(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $customer  = \App\Modules\Customers\Models\Customer::factory()->forTenant($tenant)->create();
        $quotation = $this->createDraftQuotation(['customer_id' => $customer->id]);

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $order = Order::withoutGlobalScopes()->find($accepted->converted_order_id);
        $this->assertSame($customer->id, $order->customer_id);
    }

    public function test_accept_with_convert_sets_quotation_status_to_accepted(): void
    {
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $this->assertSame('accepted', $accepted->status);
        $this->assertSame('accepted', $quotation->fresh()->status);
    }

    public function test_accept_with_convert_order_has_no_order_items(): void
    {
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $order = Order::withoutGlobalScopes()->find($accepted->converted_order_id);
        $this->assertSame(0, $order->items()->count());
    }

    public function test_accept_with_convert_writes_initial_order_status_history_row(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = $this->createDraftQuotation();
        $accepted  = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $order = Order::withoutGlobalScopes()->find($accepted->converted_order_id);

        $historyRow = OrderStatusHistory::withoutGlobalScopes()
            ->where('order_id', $order->id)
            ->firstOrFail();

        $this->assertNull($historyRow->from_status);
        $this->assertSame('pending', $historyRow->to_status);
        $this->assertSame('Created from quotation', $historyRow->note);
        $this->assertSame($tenant->id, $historyRow->tenant_id);
    }

    public function test_accept_with_convert_tax_breakdown_carried_correctly(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $tenant->update(['quotation_tax_rate_bps' => 1300]); // 13% IVA

        // subtotal = 10000, tax = 1300, total = 11300
        $quotation = $this->createDraftQuotation([
            'items'        => [['description' => 'Flores', 'quantity' => 1, 'unit_price_cents' => 10000]],
            'tax_rate_bps' => 1300,
        ]);

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $order = Order::withoutGlobalScopes()->find($accepted->converted_order_id);

        $this->assertSame(10000, $order->subtotal_cents);
        $this->assertSame(1300, $order->tax_cents);
        $this->assertSame(0, $order->discount_cents);
        $this->assertSame(11300, $order->total_cents);
    }

    // ── Conversion: accept without convert ───────────────────────────────────

    public function test_accept_without_convert_flag_is_plain_transition(): void
    {
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        $accepted = $this->service->accept(
            quotation: $quotation,
            convertToOrder: false,
        );

        $this->assertSame('accepted', $accepted->status);
        $this->assertNull($quotation->fresh()->converted_order_id);
        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_accept_default_is_plain_transition_no_order_created(): void
    {
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        // No convertToOrder argument — default should be false
        $this->service->accept(quotation: $quotation);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
        $this->assertNull($quotation->fresh()->converted_order_id);
    }

    // ── Conversion: idempotency guard ─────────────────────────────────────────

    public function test_second_conversion_of_same_quotation_throws_domain_exception(): void
    {
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/already been converted/');

        $this->service->accept(quotation: $accepted->fresh(), convertToOrder: true);
    }

    public function test_double_conversion_does_not_create_a_second_order(): void
    {
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        try {
            $this->service->accept(quotation: $accepted->fresh(), convertToOrder: true);
        } catch (DomainException) {
            // expected
        }

        // Still only one order exists
        $this->assertSame(1, Order::withoutGlobalScopes()->count());
    }

    public function test_accepting_already_accepted_quotation_without_convert_throws(): void
    {
        // transitionTo() blocks this — expected DomainException from state machine
        $this->setupTenant();

        $quotation = $this->createDraftQuotation();
        $this->service->accept(quotation: $quotation);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot transition/');

        $this->service->accept(quotation: $quotation->fresh());
    }

    // ── Conversion: branch resolution ─────────────────────────────────────────

    public function test_conversion_uses_explicit_branch_when_set(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $nonMainBranch = Branch::factory()->forTenant($tenant)->create(['is_main' => false]);

        $quotation = $this->createDraftQuotation(['branch_id' => $nonMainBranch->id]);

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $order = Order::withoutGlobalScopes()->find($accepted->converted_order_id);
        $this->assertSame($nonMainBranch->id, $order->branch_id);
    }

    public function test_conversion_falls_back_to_main_branch_when_quotation_has_no_branch(): void
    {
        ['tenant' => $tenant, 'branch' => $mainBranch] = $this->setupTenant();

        // Create quotation with no branch_id
        $quotation = $this->createDraftQuotation(['branch_id' => null]);

        $accepted = $this->service->accept(quotation: $quotation, convertToOrder: true);

        $order = Order::withoutGlobalScopes()->find($accepted->converted_order_id);
        $this->assertSame($mainBranch->id, $order->branch_id);
    }

    public function test_conversion_throws_when_no_branch_exists(): void
    {
        $tenant = Tenant::factory()->create([
            'quotation_tax_rate_bps' => 0,
            'quotation_valid_days'   => 15,
        ]);
        // No branch created — not even a main one
        app()->instance('currentTenant', $tenant);

        $quotation = $this->createDraftQuotation(['branch_id' => null]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/No branch available to convert/');

        $this->service->accept(quotation: $quotation, convertToOrder: true);
    }

    // ── ExpireQuotationsJob ───────────────────────────────────────────────────

    public function test_draft_quotation_with_past_valid_until_is_expired(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'draft',
            'valid_until' => now()->subDay()->toDateString(), // yesterday
        ]);

        (new ExpireQuotationsJob())->handle();

        $this->assertSame('expired', $quotation->fresh()->status);
    }

    public function test_sent_quotation_with_past_valid_until_is_expired(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'sent',
            'valid_until' => now()->subDays(3)->toDateString(),
        ]);

        (new ExpireQuotationsJob())->handle();

        $this->assertSame('expired', $quotation->fresh()->status);
    }

    public function test_accepted_quotation_past_valid_until_is_not_touched(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'accepted',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        (new ExpireQuotationsJob())->handle();

        $this->assertSame('accepted', $quotation->fresh()->status);
    }

    public function test_rejected_quotation_past_valid_until_is_not_touched(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'rejected',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        (new ExpireQuotationsJob())->handle();

        $this->assertSame('rejected', $quotation->fresh()->status);
    }

    public function test_already_expired_quotation_past_valid_until_is_not_touched(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'expired',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        (new ExpireQuotationsJob())->handle();

        // Still expired — no duplicate history row expected
        $this->assertSame('expired', $quotation->fresh()->status);

        $historyCount = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $quotation->id)
            ->where('to_status', 'expired')
            ->count();

        // The factory creates without a history row, so count is 0 (not incremented)
        $this->assertSame(0, $historyCount);
    }

    public function test_quotation_expiring_today_is_not_expired(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'draft',
            'valid_until' => now()->toDateString(), // today = still valid
        ]);

        (new ExpireQuotationsJob())->handle();

        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_quotation_with_null_valid_until_is_not_expired(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'draft',
            'valid_until' => null, // no expiry date
        ]);

        (new ExpireQuotationsJob())->handle();

        $this->assertSame('draft', $quotation->fresh()->status);
    }

    public function test_expiry_job_writes_history_row_with_null_user_and_correct_statuses(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $quotation = Quotation::factory()->forTenant($tenant)->create([
            'status'      => 'sent',
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        (new ExpireQuotationsJob())->handle();

        $historyRow = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $quotation->id)
            ->where('to_status', 'expired')
            ->firstOrFail();

        $this->assertSame('sent', $historyRow->from_status);
        $this->assertSame('expired', $historyRow->to_status);
        $this->assertNull($historyRow->user_id, 'System transition must record null user_id');
        $this->assertSame('Vencida automaticamente', $historyRow->note);
        $this->assertSame($tenant->id, $historyRow->tenant_id);
    }

    public function test_expiry_job_processes_multiple_tenants_in_isolation(): void
    {
        // Tenant A: 2 quotations to expire
        $tenantA = Tenant::factory()->create(['quotation_tax_rate_bps' => 0, 'quotation_valid_days' => 15]);
        Branch::factory()->forTenant($tenantA)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenantA);

        $qA1 = Quotation::factory()->forTenant($tenantA)->create([
            'status' => 'draft', 'valid_until' => now()->subDays(2)->toDateString(),
        ]);
        $qA2 = Quotation::factory()->forTenant($tenantA)->create([
            'status' => 'sent', 'valid_until' => now()->subDay()->toDateString(),
        ]);

        // Tenant B: 1 quotation NOT expired (valid_until = today)
        $tenantB = Tenant::factory()->create(['quotation_tax_rate_bps' => 0, 'quotation_valid_days' => 15]);
        Branch::factory()->forTenant($tenantB)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenantB);

        $qB = Quotation::factory()->forTenant($tenantB)->create([
            'status' => 'draft', 'valid_until' => now()->toDateString(),
        ]);

        (new ExpireQuotationsJob())->handle();

        $this->assertSame('expired', $qA1->fresh()->status, 'Tenant A qA1 must be expired');
        $this->assertSame('expired', $qA2->fresh()->status, 'Tenant A qA2 must be expired');
        $this->assertSame('draft', $qB->fresh()->status, 'Tenant B qB must remain draft (valid_until=today)');

        // History rows must carry the correct tenant_id for each quotation
        $histA1 = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $qA1->id)->where('to_status', 'expired')->firstOrFail();
        $this->assertSame($tenantA->id, $histA1->tenant_id);

        $histA2 = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $qA2->id)->where('to_status', 'expired')->firstOrFail();
        $this->assertSame($tenantA->id, $histA2->tenant_id);

        // Tenant B's quotation must have no 'expired' history row
        $this->assertSame(
            0,
            QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('quotation_id', $qB->id)->where('to_status', 'expired')->count()
        );
    }

    // ── API: POST /quotations/{id}/accept with convert_to_order ───────────────

    public function test_api_accept_with_convert_to_order_returns_accepted_quotation_with_order_id(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        $response = $this->tenantPostJson(
            tenant: $tenant,
            user: $owner,
            uri: "/api/v1/quotations/{$quotation->id}/accept",
            data: ['convert_to_order' => true],
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonStructure(['data' => ['converted_order_id']]);

        $this->assertNotNull($response->json('data.converted_order_id'));

        // Verify the Order row exists and has the expected source
        $orderId = $response->json('data.converted_order_id');
        $order   = Order::withoutGlobalScopes()->find($orderId);
        $this->assertNotNull($order);
        $this->assertSame('quotation', $order->source);
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->payment_status);
    }

    public function test_api_accept_without_convert_to_order_leaves_converted_order_id_null(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createDraftQuotation();

        $response = $this->tenantPostJson(
            tenant: $tenant,
            user: $owner,
            uri: "/api/v1/quotations/{$quotation->id}/accept",
            data: [],
        );

        $response->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.converted_order_id', null);

        $this->assertSame(0, Order::withoutGlobalScopes()->count());
    }

    public function test_api_accept_already_accepted_quotation_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createDraftQuotation();
        // Accept first (plain, no convert)
        $this->service->accept(quotation: $quotation);

        $response = $this->tenantPostJson(
            tenant: $tenant,
            user: $owner,
            uri: "/api/v1/quotations/{$quotation->id}/accept",
            data: [],
        );

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'quotations.invalid_transition');
    }

    public function test_api_accept_already_converted_quotation_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createDraftQuotation();
        $this->service->accept(quotation: $quotation, convertToOrder: true);

        $response = $this->tenantPostJson(
            tenant: $tenant,
            user: $owner,
            uri: "/api/v1/quotations/{$quotation->id}/accept",
            data: ['convert_to_order' => true],
        );

        $response->assertStatus(422)
            ->assertJsonPath('error_code', 'quotations.invalid_transition');
    }
}
