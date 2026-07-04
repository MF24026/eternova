<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Models\User;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationItem;
use App\Modules\Quotations\Models\QuotationSequence;
use App\Modules\Quotations\Models\QuotationStatusHistory;
use App\Modules\Quotations\Services\QuotationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Carbon\Carbon;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for QuotationService: sequence, totals, creation, update,
 * and the status state machine with audit history.
 *
 * Each test sets up its own isolated tenant to prevent cross-test pollution.
 * The RefreshDatabase trait resets the DB between tests.
 */
final class QuotationServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuotationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(QuotationService::class);
    }

    // ── Setup helpers ─────────────────────────────────────────────────────────

    /**
     * @return array{tenant: Tenant, branch: Branch, user: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $user = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'user');
    }

    /**
     * Minimal valid line payload for create/update calls.
     *
     * @return list<array<string, mixed>>
     */
    private function twoLines(): array
    {
        return [
            ['description' => 'Rose arrangement',   'quantity' => 2, 'unit_price_cents' => 5000, 'sort_order' => 0],
            ['description' => 'Delivery surcharge',  'quantity' => 1, 'unit_price_cents' => 1500, 'sort_order' => 1],
        ];
    }

    // ── Sequence tests ────────────────────────────────────────────────────────

    public function test_first_quotation_of_the_year_gets_sequence_0001(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $year = (int) date('Y');
        $this->assertSame("COT-{$year}-0001", $q->quotation_number);
    }

    public function test_second_quotation_gets_sequence_0002(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $q2 = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $year = (int) date('Y');
        $this->assertSame("COT-{$year}-0002", $q2->quotation_number);
    }

    public function test_two_different_tenants_both_start_at_0001(): void
    {
        ['tenant' => $tenantA] = $this->setupTenant();

        $qA = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $tenantB = Tenant::factory()->create();
        Branch::factory()->forTenant($tenantB)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenantB);

        $qB = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $year = (int) date('Y');
        $this->assertSame("COT-{$year}-0001", $qA->quotation_number);
        $this->assertSame("COT-{$year}-0001", $qB->quotation_number, 'Tenant B must start its own sequence at 0001');
    }

    public function test_sequence_advances_without_gaps(): void
    {
        $this->setupTenant();

        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $q = $this->service->create([
                'issue_date' => now()->toDateString(),
                'items' => $this->twoLines(),
            ]);
            $numbers[] = $q->quotation_number;
        }

        $year = (int) date('Y');

        $this->assertSame([
            "COT-{$year}-0001",
            "COT-{$year}-0002",
            "COT-{$year}-0003",
            "COT-{$year}-0004",
            "COT-{$year}-0005",
        ], $numbers);
    }

    public function test_new_year_resets_sequence_for_tenant(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        // Manually plant a sequence row for 2025 to simulate last-year state.
        QuotationSequence::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'year' => 2025,
            'last_sequence' => 42,
        ]);

        // Creating in the current year should start a fresh sequence.
        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $year = (int) date('Y');
        $this->assertSame("COT-{$year}-0001", $q->quotation_number);
    }

    // ── calculateTotals tests ─────────────────────────────────────────────────

    public function test_subtotal_equals_sum_of_line_totals(): void
    {
        $lines = [
            ['quantity' => 3, 'unit_price_cents' => 2000],
            ['quantity' => 1, 'unit_price_cents' => 5000],
        ];

        $totals = $this->service->calculateTotals(lines: $lines, discountCents: 0, taxRateBps: 0);

        // 3*2000 + 1*5000 = 11000
        $this->assertSame(11000, $totals['subtotal_cents']);
        $this->assertSame(0, $totals['discount_cents']);
        $this->assertSame(0, $totals['tax_cents']);
        $this->assertSame(11000, $totals['total_cents']);
    }

    public function test_discount_reduces_taxable_base(): void
    {
        $lines = [['quantity' => 1, 'unit_price_cents' => 10000]];

        $totals = $this->service->calculateTotals(lines: $lines, discountCents: 2000, taxRateBps: 0);

        $this->assertSame(10000, $totals['subtotal_cents']);
        $this->assertSame(2000, $totals['discount_cents']);
        $this->assertSame(0, $totals['tax_cents']);
        // total = (10000 - 2000) + 0 = 8000
        $this->assertSame(8000, $totals['total_cents']);
    }

    public function test_tax_computed_from_bps_correctly(): void
    {
        // Standard case: subtotal=10000, discount=0, 13% IVA (1300 bps)
        // tax = intdiv(10000 * 1300, 10000) = intdiv(13000000, 10000) = 1300
        $lines = [['quantity' => 1, 'unit_price_cents' => 10000]];

        $totals = $this->service->calculateTotals(lines: $lines, discountCents: 0, taxRateBps: 1300);

        $this->assertSame(10000, $totals['subtotal_cents']);
        $this->assertSame(1300, $totals['tax_cents']);
        $this->assertSame(11300, $totals['total_cents']);
    }

    public function test_tax_computed_after_discount(): void
    {
        // subtotal=10000, discount=2000, taxable=8000, 13% IVA
        // tax = intdiv(8000 * 1300, 10000) = intdiv(10400000, 10000) = 1040
        $lines = [['quantity' => 1, 'unit_price_cents' => 10000]];

        $totals = $this->service->calculateTotals(lines: $lines, discountCents: 2000, taxRateBps: 1300);

        $this->assertSame(8000, $totals['subtotal_cents'] - $totals['discount_cents']);
        $this->assertSame(1040, $totals['tax_cents']);
        $this->assertSame(9040, $totals['total_cents']); // 8000 + 1040
    }

    public function test_discount_greater_than_subtotal_is_clamped(): void
    {
        // discount=99999 > subtotal=5000 → clamped to 5000 → taxableBase=0
        $lines = [['quantity' => 1, 'unit_price_cents' => 5000]];

        $totals = $this->service->calculateTotals(lines: $lines, discountCents: 99999, taxRateBps: 1300);

        $this->assertSame(5000, $totals['subtotal_cents']);
        // Clamped discount stored equals the subtotal.
        $this->assertSame(5000, $totals['discount_cents']);
        $this->assertSame(0, $totals['tax_cents']);
        $this->assertSame(0, $totals['total_cents']);
    }

    public function test_multiple_lines_subtotal_is_correct(): void
    {
        $lines = [
            ['quantity' => 2, 'unit_price_cents' => 3000],
            ['quantity' => 5, 'unit_price_cents' => 1000],
            ['quantity' => 1, 'unit_price_cents' => 7500],
        ];

        $totals = $this->service->calculateTotals(lines: $lines, discountCents: 0, taxRateBps: 0);

        // 2*3000 + 5*1000 + 1*7500 = 6000 + 5000 + 7500 = 18500
        $this->assertSame(18500, $totals['subtotal_cents']);
    }

    public function test_tax_truncates_towards_zero_on_odd_bps(): void
    {
        // taxable=10000, rate=1750 bps (17.5%)
        // exact = 10000 * 0.175 = 1750.0 (exact in this case)
        // taxable=10000, rate=1333 bps (13.33%)
        // intdiv(10000 * 1333, 10000) = intdiv(13330000, 10000) = 1333
        $lines = [['quantity' => 1, 'unit_price_cents' => 10000]];

        $totals = $this->service->calculateTotals(lines: $lines, discountCents: 0, taxRateBps: 1333);

        $this->assertSame(1333, $totals['tax_cents']);

        // A case that would round differently: taxable=1 cent, rate=9999 bps
        // intdiv(1 * 9999, 10000) = intdiv(9999, 10000) = 0  (truncated, not rounded to 1)
        $lines2 = [['quantity' => 1, 'unit_price_cents' => 1]];
        $totals2 = $this->service->calculateTotals(lines: $lines2, discountCents: 0, taxRateBps: 9999);

        $this->assertSame(0, $totals2['tax_cents'], 'sub-centavo tax must truncate to 0, not round to 1');
    }

    public function test_empty_lines_produce_zero_totals(): void
    {
        $totals = $this->service->calculateTotals(lines: [], discountCents: 0, taxRateBps: 1300);

        $this->assertSame(0, $totals['subtotal_cents']);
        $this->assertSame(0, $totals['discount_cents']);
        $this->assertSame(0, $totals['tax_cents']);
        $this->assertSame(0, $totals['total_cents']);
    }

    // ── Creation tests ────────────────────────────────────────────────────────

    public function test_creation_persists_quotation_with_items(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $this->assertSame($tenant->id, $q->tenant_id);
        $this->assertSame('draft', $q->fresh()->status);
        $this->assertSame(2, $q->items()->count());
    }

    public function test_creation_applies_tenant_default_tax_rate_bps_when_not_provided(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $tenant->update(['quotation_tax_rate_bps' => 1300]);

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => [['description' => 'A', 'quantity' => 1, 'unit_price_cents' => 10000]],
        ]);

        $fresh = $q->fresh();
        $this->assertSame(1300, $fresh->tax_rate_bps);
        $this->assertSame(1300, $fresh->tax_cents);
        $this->assertSame(11300, $fresh->total_cents);
    }

    public function test_creation_applies_tenant_default_valid_until_when_not_provided(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $tenant->update(['quotation_valid_days' => 30]);

        $issueDate = now()->toDateString();

        $q = $this->service->create([
            'issue_date' => $issueDate,
            'items' => $this->twoLines(),
        ]);

        $expectedValidUntil = Carbon::parse($issueDate)->addDays(30)->toDateString();

        $this->assertSame($expectedValidUntil, $q->fresh()->valid_until->toDateString());
    }

    public function test_creation_applies_tenant_default_terms_when_not_provided(): void
    {
        $this->setupTenant();

        /** @var Tenant $tenant */
        $tenant = app('currentTenant');
        $tenant->update(['quotation_terms' => 'Payment due in 15 days.']);

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $this->assertSame('Payment due in 15 days.', $q->fresh()->terms);
    }

    public function test_creation_caller_provided_values_override_tenant_defaults(): void
    {
        $this->setupTenant();

        /** @var Tenant $tenant */
        $tenant = app('currentTenant');
        $tenant->update([
            'quotation_tax_rate_bps' => 1300,
            'quotation_valid_days' => 30,
            'quotation_terms' => 'Default terms',
        ]);

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'tax_rate_bps' => 1900, // caller override
            'valid_until' => now()->addDays(10)->toDateString(),
            'terms' => 'Custom terms for this quotation',
            'items' => [['description' => 'A', 'quantity' => 1, 'unit_price_cents' => 10000]],
        ]);

        $fresh = $q->fresh();
        $this->assertSame(1900, $fresh->tax_rate_bps);
        $this->assertSame('Custom terms for this quotation', $fresh->terms);
    }

    public function test_creation_writes_initial_history_row(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $history = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $q->id)
            ->get();

        $this->assertSame(1, $history->count());

        $row = $history->first();
        $this->assertNull($row->from_status);
        $this->assertSame('draft', $row->to_status);
        $this->assertSame($tenant->id, $row->tenant_id);
    }

    public function test_creation_with_actor_stores_created_by(): void
    {
        ['user' => $user] = $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ], actor: $user);

        $this->assertSame($user->id, $q->fresh()->created_by);
    }

    public function test_creation_item_line_total_cents_is_computed(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => [
                ['description' => 'Flower', 'quantity' => 3, 'unit_price_cents' => 2500, 'sort_order' => 0],
            ],
        ]);

        $item = $q->items()->first();
        // 3 * 2500 = 7500
        $this->assertSame(7500, $item->line_total_cents);
    }

    public function test_creation_items_persist_with_correct_sort_order(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => [
                ['description' => 'B', 'quantity' => 1, 'unit_price_cents' => 100, 'sort_order' => 1],
                ['description' => 'A', 'quantity' => 1, 'unit_price_cents' => 200, 'sort_order' => 0],
            ],
        ]);

        $items = $q->items()->orderBy('sort_order')->get();

        $this->assertSame(0, $items->first()->sort_order);
        $this->assertSame('A', $items->first()->description);
        $this->assertSame(1, $items->last()->sort_order);
        $this->assertSame('B', $items->last()->description);
    }

    // ── Update tests ──────────────────────────────────────────────────────────

    public function test_update_replaces_lines_and_recomputes_totals(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => [['description' => 'Old item', 'quantity' => 1, 'unit_price_cents' => 1000]],
        ]);

        $updated = $this->service->update($q, [
            'items' => [
                ['description' => 'New item A', 'quantity' => 2, 'unit_price_cents' => 3000, 'sort_order' => 0],
                ['description' => 'New item B', 'quantity' => 1, 'unit_price_cents' => 500,  'sort_order' => 1],
            ],
        ]);

        // 2*3000 + 1*500 = 6500
        $this->assertSame(6500, $updated->subtotal_cents);
        $this->assertSame(2, $updated->items()->count());

        // Old item must be gone
        $this->assertSame(0, QuotationItem::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $q->id)
            ->where('description', 'Old item')
            ->count());
    }

    public function test_update_non_draft_quotation_throws_domain_exception(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        // Advance to sent — no longer a draft
        $this->service->markSent($q);

        $q = $q->fresh();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/only draft quotations are editable/');

        $this->service->update($q, ['items' => $this->twoLines()]);
    }

    public function test_update_of_accepted_quotation_throws_domain_exception(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $this->service->accept($q);
        $q = $q->fresh();

        $this->expectException(DomainException::class);

        $this->service->update($q, ['items' => $this->twoLines()]);
    }

    // ── State machine: valid transitions ──────────────────────────────────────

    public function test_draft_can_transition_to_sent(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $result = $this->service->markSent($q);

        $this->assertSame('sent', $result->status);
        $this->assertSame('sent', $q->fresh()->status);
    }

    public function test_draft_can_transition_to_accepted_directly(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $result = $this->service->accept($q);

        $this->assertSame('accepted', $result->status);
    }

    public function test_draft_can_transition_to_rejected(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $result = $this->service->reject($q);

        $this->assertSame('rejected', $result->status);
    }

    public function test_sent_can_transition_to_accepted(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $q = $this->service->markSent($q);
        $q = $this->service->accept($q);

        $this->assertSame('accepted', $q->status);
    }

    public function test_sent_can_transition_to_rejected(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $q = $this->service->markSent($q);
        $q = $this->service->reject($q);

        $this->assertSame('rejected', $q->status);
    }

    // ── State machine: invalid transitions ────────────────────────────────────

    public function test_accepted_quotation_rejects_all_transitions(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $q = $this->service->accept($q);

        foreach (['draft', 'sent', 'rejected', 'expired'] as $status) {
            $thrown = false;

            try {
                $this->service->transitionTo($q->fresh(), $status);
            } catch (DomainException) {
                $thrown = true;
            }

            $this->assertTrue($thrown, "Expected DomainException when transitioning accepted → {$status}");
        }
    }

    public function test_rejected_quotation_rejects_all_transitions(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $q = $this->service->reject($q);

        foreach (['draft', 'sent', 'accepted', 'expired'] as $status) {
            $thrown = false;

            try {
                $this->service->transitionTo($q->fresh(), $status);
            } catch (DomainException) {
                $thrown = true;
            }

            $this->assertTrue($thrown, "Expected DomainException when transitioning rejected → {$status}");
        }
    }

    public function test_invalid_transition_throws_domain_exception(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        // draft → accepted is valid; accepted → sent is not
        $q = $this->service->accept($q);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/Cannot transition quotation.*accepted.*sent/');

        $this->service->transitionTo($q, 'sent');
    }

    public function test_unknown_status_throws_domain_exception(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/not a recognised quotation status/');

        $this->service->transitionTo($q, 'flying');
    }

    public function test_invalid_transition_does_not_mutate_quotation_status(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $q = $this->service->accept($q);

        try {
            $this->service->transitionTo($q->fresh(), 'sent');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame('accepted', $q->fresh()->status);
    }

    public function test_invalid_transition_does_not_write_history_row(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $q = $this->service->accept($q);

        $countBefore = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $q->id)
            ->count();

        try {
            $this->service->transitionTo($q->fresh(), 'sent');
        } catch (DomainException) {
            // expected
        }

        $countAfter = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $q->id)
            ->count();

        $this->assertSame($countBefore, $countAfter);
    }

    // ── History tests ─────────────────────────────────────────────────────────

    public function test_each_transition_writes_exactly_one_history_row(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        // At creation: 1 row (initial history from create())
        $this->assertSame(
            1,
            QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('quotation_id', $q->id)->count()
        );

        $q = $this->service->markSent($q);

        $this->assertSame(
            2,
            QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('quotation_id', $q->id)->count()
        );

        $q = $this->service->accept($q);

        $this->assertSame(
            3,
            QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
                ->where('quotation_id', $q->id)->count()
        );
    }

    public function test_history_row_carries_correct_from_to_actor_and_note(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $this->service->transitionTo($q, 'sent', $user, 'Customer called, sending now');

        $this->assertDatabaseHas('quotation_status_history', [
            'quotation_id' => $q->id,
            'tenant_id' => $tenant->id,
            'from_status' => 'draft',
            'to_status' => 'sent',
            'user_id' => $user->id,
            'note' => 'Customer called, sending now',
        ]);
    }

    public function test_history_row_with_null_actor_records_null_user_id(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $this->service->transitionTo($q, 'sent', actor: null);

        $row = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $q->id)
            ->where('to_status', 'sent')
            ->firstOrFail();

        $this->assertNull($row->user_id);
    }

    public function test_transition_returns_fresh_quotation_with_status_history_loaded(): void
    {
        $this->setupTenant();

        $q = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $result = $this->service->markSent($q);

        $this->assertTrue($result->relationLoaded('statusHistory'));
        $this->assertNotEmpty($result->statusHistory);
    }

    // ── recordInitialHistory tests ────────────────────────────────────────────

    public function test_record_initial_history_writes_null_from_status_row(): void
    {
        ['tenant' => $tenant, 'user' => $user] = $this->setupTenant();

        // Create a quotation directly via factory (no service, so no auto history)
        $q = Quotation::factory()->forTenant($tenant)->create(['status' => 'draft']);

        $this->service->recordInitialHistory($q, $user);

        $this->assertDatabaseHas('quotation_status_history', [
            'quotation_id' => $q->id,
            'tenant_id' => $tenant->id,
            'from_status' => null,
            'to_status' => 'draft',
            'user_id' => $user->id,
        ]);
    }

    public function test_record_initial_history_with_null_actor_records_null_user_id(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $q = Quotation::factory()->forTenant($tenant)->create();

        $this->service->recordInitialHistory($q, actor: null);

        $row = QuotationStatusHistory::withoutGlobalScope(TenantScope::class)
            ->where('quotation_id', $q->id)
            ->firstOrFail();

        $this->assertNull($row->user_id);
        $this->assertSame('draft', $row->to_status);
    }

    // ── allowedTransitions tests ──────────────────────────────────────────────

    public function test_allowed_transitions_returns_correct_set_for_each_status(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $cases = [
            'draft' => ['sent', 'accepted', 'rejected', 'expired'],
            'sent' => ['accepted', 'rejected', 'expired'],
            'accepted' => [],
            'rejected' => [],
            'expired' => [],
        ];

        foreach ($cases as $status => $expected) {
            $q = Quotation::factory()->forTenant($tenant)->create(['status' => $status]);

            $actual = $this->service->allowedTransitions($q);

            $this->assertSame(
                $expected,
                $actual,
                "Allowed transitions for '{$status}' did not match expected set."
            );
        }
    }

    // ── Multi-tenant isolation ────────────────────────────────────────────────

    public function test_history_rows_are_scoped_to_current_tenant(): void
    {
        ['tenant' => $tenantA, 'user' => $userA] = $this->setupTenant();

        $qA = $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);

        $this->service->markSent($qA, $userA);

        // Switch context to Tenant B
        $tenantB = Tenant::factory()->create();
        app()->instance('currentTenant', $tenantB);

        // Under Tenant B's scope, Tenant A's history rows must not be visible.
        $visible = QuotationStatusHistory::where('quotation_id', $qA->id)->count();

        $this->assertSame(0, $visible, 'Tenant B must not see Tenant A history through tenant scope');
    }

    public function test_quotations_scoped_per_tenant_after_creation(): void
    {
        ['tenant' => $tenantA] = $this->setupTenant();

        // Tenant A creates 2 quotations
        $this->service->create(['issue_date' => now()->toDateString(), 'items' => $this->twoLines()]);
        $this->service->create(['issue_date' => now()->toDateString(), 'items' => $this->twoLines()]);

        // Tenant B creates 1 quotation
        $tenantB = Tenant::factory()->create();
        Branch::factory()->forTenant($tenantB)->create(['is_main' => true]);
        app()->instance('currentTenant', $tenantB);

        $this->service->create(['issue_date' => now()->toDateString(), 'items' => $this->twoLines()]);

        // Tenant B sees only its own quotation
        $this->assertSame(1, Quotation::count());

        // Tenant A sees only its own 2
        app()->instance('currentTenant', $tenantA);
        $this->assertSame(2, Quotation::count());
    }

    // ── No-tenant context test ────────────────────────────────────────────────

    public function test_create_without_tenant_context_throws_domain_exception(): void
    {
        // Unbind the tenant so there is no active context
        app()->forgetInstance('currentTenant');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/No active tenant context/');

        $this->service->create([
            'issue_date' => now()->toDateString(),
            'items' => $this->twoLines(),
        ]);
    }
}
