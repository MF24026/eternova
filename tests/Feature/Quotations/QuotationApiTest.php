<?php

declare(strict_types=1);

namespace Tests\Feature\Quotations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Services\QuotationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the Quotations Admin API (S7-E3).
 *
 * Verifies:
 *   - index returns paginated quotations scoped to current tenant only
 *   - index status_counts spans all five statuses and ignores the status filter
 *   - index filters (status, customer_id, date range, search) work correctly
 *   - show returns full detail (items + history); cross-tenant id → 404
 *   - store creates quotation with number + items + initial history at 201
 *   - update on a draft replaces items + recalculates totals
 *   - update on a non-draft (sent) returns 422
 *   - destroy soft-deletes the quotation (204) and it disappears from index
 *   - send/accept/reject transitions update status; invalid transition → 422
 *   - auth gates: 401 unauthenticated, 403 customer role
 *   - multi-tenant boundary: tenant B user cannot access tenant A quotations
 */
final class QuotationApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private QuotationService $quotationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->quotationService = app(QuotationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal tenant with a branch and an owner user.
     *
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create([
            'quotation_tax_rate_bps' => 0,
            'quotation_valid_days' => 15,
        ]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    /**
     * Create a quotation via the service (so it has a proper number + history row).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function createQuotation(Tenant $tenant, User $actor, array $overrides = []): Quotation
    {
        app()->instance('currentTenant', $tenant);

        return $this->quotationService->create(
            data: array_merge([
                'issue_date' => now()->toDateString(),
                'items' => [
                    ['description' => 'Arreglo floral', 'quantity' => 2, 'unit_price_cents' => 5000],
                ],
            ], $overrides),
            actor: $actor,
        );
    }

    /** Minimal valid item payload. */
    private function defaultItems(): array
    {
        return [
            ['description' => 'Rosa eterna', 'quantity' => 1, 'unit_price_cents' => 3000],
        ];
    }

    // ── index: auth gates ─────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->tenantUrl($tenant, 'api/v1/quotations'))
            ->assertStatus(401);
    }

    public function test_customer_role_cannot_list_quotations(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $customer = User::factory()->forTenant($tenant, role: 'customer')->create();

        $this->tenantGetJson($tenant, $customer, '/api/v1/quotations')
            ->assertStatus(403);
    }

    // ── index: pagination + tenant isolation ─────────────────────────────────

    public function test_index_returns_paginated_quotations_for_current_tenant_only(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();

        $this->createQuotation($tenantA, $ownerA);
        $this->createQuotation($tenantA, $ownerA);

        // Three quotations for tenant B — must not bleed into tenant A's response.
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();
        $this->createQuotation($tenantB, $ownerB);
        $this->createQuotation($tenantB, $ownerB);
        $this->createQuotation($tenantB, $ownerB);

        $response = $this->tenantGetJson($tenantA, $ownerA, '/api/v1/quotations')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id'],
                'status_counts',
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_response_includes_correct_envelope(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantGetJson($tenant, $owner, '/api/v1/quotations')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id', 'request_id'],
                'status_counts',
            ]);
    }

    // ── index: status_counts ──────────────────────────────────────────────────

    public function test_index_status_counts_returns_all_five_statuses(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/quotations')
            ->assertOk();

        $counts = $response->json('status_counts');

        $this->assertArrayHasKey('draft', $counts);
        $this->assertArrayHasKey('sent', $counts);
        $this->assertArrayHasKey('accepted', $counts);
        $this->assertArrayHasKey('rejected', $counts);
        $this->assertArrayHasKey('expired', $counts);
    }

    public function test_index_status_counts_are_correct_values(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        $this->createQuotation($tenant, $owner);   // draft
        $this->createQuotation($tenant, $owner);   // draft
        Quotation::factory()->forBranch($branch)->sent()->create();

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/quotations')
            ->assertOk();

        $counts = $response->json('status_counts');

        $this->assertSame(2, $counts['draft']);
        $this->assertSame(1, $counts['sent']);
        $this->assertSame(0, $counts['accepted']);
    }

    public function test_index_status_counts_ignore_the_status_filter(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        $this->createQuotation($tenant, $owner);  // draft
        Quotation::factory()->forBranch($branch)->sent()->create();

        // Filter by status=draft → data only has draft quotations,
        // but counts must still include sent=1.
        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/quotations?status=draft')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));

        $counts = $response->json('status_counts');
        $this->assertSame(1, $counts['draft']);
        $this->assertSame(1, $counts['sent']);
    }

    // ── index: filters ────────────────────────────────────────────────────────

    public function test_index_filter_by_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);
        $this->createQuotation($tenant, $owner);  // draft
        Quotation::factory()->forBranch($branch)->sent()->create();

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/quotations?status=sent')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('sent', $response->json('data.0.status'));
    }

    public function test_index_filter_by_customer_id(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $customer = Customer::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);

        $this->createQuotation($tenant, $owner, ['customer_id' => $customer->id]);
        $this->createQuotation($tenant, $owner); // no customer

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations?customer_id={$customer->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_filter_by_date_range(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        Quotation::factory()->forBranch($branch)->create([
            'issue_date' => now()->subDays(30)->toDateString(),
        ]);
        Quotation::factory()->forBranch($branch)->create([
            'issue_date' => now()->toDateString(),
        ]);

        $from = now()->subDays(5)->toDateString();
        $to = now()->addDays(5)->toDateString();

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations?date_from={$from}&date_to={$to}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_search_by_quotation_number(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        Quotation::factory()->forBranch($branch)->create(['quotation_number' => 'COT-2026-0042']);
        Quotation::factory()->forBranch($branch)->create(['quotation_number' => 'COT-2026-0099']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/quotations?search=0042')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('COT-2026-0042', $response->json('data.0.quotation_number'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_quotation_with_items_and_status_history(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'quotation_number',
                    'status',
                    'subtotal_cents',
                    'total_cents',
                    'allowed_transitions',
                    'items',
                    'status_history',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.items'));
        $this->assertNotEmpty($response->json('data.status_history'));
    }

    public function test_show_cross_tenant_quotation_id_is_not_accessible(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $quotationB = $this->createQuotation($tenantB, $ownerB);

        // BelongsToTenant scope hides tenant B's resource → 404 for tenant A user.
        $response = $this->tenantGetJson($tenantA, $ownerA, "/api/v1/quotations/{$quotationB->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_show_returns_customer_data_when_linked(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $customer = Customer::factory()->forTenant($tenant)->create();

        $quotation = $this->createQuotation($tenant, $owner, ['customer_id' => $customer->id]);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}")
            ->assertOk();

        $this->assertSame($customer->id, $response->json('data.customer.id'));
        $this->assertSame($customer->name, $response->json('data.customer.name'));
    }

    public function test_show_includes_allowed_transitions(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}")
            ->assertOk();

        // 'draft' → allowed: ['sent', 'accepted', 'rejected', 'expired']
        $allowed = $response->json('data.allowed_transitions');
        $this->assertContains('sent', $allowed);
        $this->assertContains('accepted', $allowed);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_quotation_with_number_items_and_initial_history(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/quotations', [
            'issue_date' => now()->toDateString(),
            'items' => [
                ['description' => 'Bouquet especial', 'quantity' => 3, 'unit_price_cents' => 4500],
            ],
        ])->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'quotation_number',
                    'status',
                    'subtotal_cents',
                    'total_cents',
                    'items',
                    'status_history',
                    'allowed_transitions',
                ],
            ]);

        $this->assertSame('draft', $response->json('data.status'));
        $this->assertStringStartsWith('COT-', (string) $response->json('data.quotation_number'));

        // subtotal = 3 * 4500 = 13500
        $this->assertSame(13500, $response->json('data.subtotal_cents'));
        $this->assertSame(13500, $response->json('data.total_cents'));

        $this->assertNotEmpty($response->json('data.items'));
        $this->assertNotEmpty($response->json('data.status_history'));
    }

    public function test_store_returns_422_when_items_is_empty(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantPostJson($tenant, $owner, '/api/v1/quotations', [
            'issue_date' => now()->toDateString(),
            'items' => [],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_store_returns_422_when_items_is_missing(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantPostJson($tenant, $owner, '/api/v1/quotations', [
            'issue_date' => now()->toDateString(),
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['items']);
    }

    public function test_store_applies_discount_and_tax_correctly(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        // 2 * 10000 = 20000 subtotal, discount = 2000, taxable = 18000
        // tax_rate_bps = 1300 (13%) → tax = intdiv(18000 * 1300, 10000) = 2340
        // total = 18000 + 2340 = 20340
        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/quotations', [
            'issue_date' => now()->toDateString(),
            'discount_cents' => 2000,
            'tax_rate_bps' => 1300,
            'items' => [
                ['description' => 'Item', 'quantity' => 2, 'unit_price_cents' => 10000],
            ],
        ])->assertStatus(201);

        $this->assertSame(20000, $response->json('data.subtotal_cents'));
        $this->assertSame(2000, $response->json('data.discount_cents'));
        $this->assertSame(2340, $response->json('data.tax_cents'));
        $this->assertSame(20340, $response->json('data.total_cents'));
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_update_on_draft_replaces_items_and_recalculates_totals(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner, [
            'items' => [['description' => 'Old item', 'quantity' => 1, 'unit_price_cents' => 1000]],
        ]);

        $response = $this->tenantPutJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}", [
            'issue_date' => now()->toDateString(),
            'items' => [
                ['description' => 'New item A', 'quantity' => 2, 'unit_price_cents' => 5000],
                ['description' => 'New item B', 'quantity' => 1, 'unit_price_cents' => 3000],
            ],
        ])->assertOk();

        // subtotal = 2*5000 + 1*3000 = 13000
        $this->assertSame(13000, $response->json('data.subtotal_cents'));
        $this->assertCount(2, $response->json('data.items'));
    }

    public function test_update_on_sent_quotation_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->quotationService->markSent($quotation, $owner);
        $quotation->refresh();

        $this->tenantPutJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}", [
            'issue_date' => now()->toDateString(),
            'items' => $this->defaultItems(),
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'quotations.not_editable');

        // Status must not have changed.
        $this->assertSame('sent', $quotation->fresh()->status);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_quotation_and_it_disappears_from_index(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        $this->tenantDeleteJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}")
            ->assertStatus(204);

        // Soft-deleted: row still in DB but excluded from the tenant-scoped query.
        $this->assertSoftDeleted('quotations', ['id' => $quotation->id]);

        $indexResponse = $this->tenantGetJson($tenant, $owner, '/api/v1/quotations')
            ->assertOk();

        $this->assertCount(0, $indexResponse->json('data'));
    }

    public function test_staff_cannot_delete_quotation(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();
        $quotation = $this->createQuotation($tenant, $owner);

        $this->tenantDeleteJson($tenant, $staff, "/api/v1/quotations/{$quotation->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('quotations', ['id' => $quotation->id, 'deleted_at' => null]);
    }

    // ── transitions: send ─────────────────────────────────────────────────────

    public function test_send_transitions_draft_to_sent(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        $response = $this->tenantPostJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/send")
            ->assertOk();

        $this->assertSame('sent', $response->json('data.status'));
        $this->assertSame('sent', $quotation->fresh()->status);
    }

    public function test_send_accepts_optional_note(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        $this->tenantPostJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/send", [
            'note' => 'Enviado al cliente por correo',
        ])->assertOk();

        $this->assertDatabaseHas('quotation_status_history', [
            'quotation_id' => $quotation->id,
            'to_status' => 'sent',
            'note' => 'Enviado al cliente por correo',
        ]);
    }

    public function test_send_from_non_draft_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        // Transition to accepted first (draft → accepted is valid)
        app()->instance('currentTenant', $tenant);
        $this->quotationService->accept($quotation, $owner);
        $quotation->refresh();

        // accepted → sent is NOT valid
        $this->tenantPostJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/send")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'quotations.invalid_transition');
    }

    // ── transitions: accept ───────────────────────────────────────────────────

    public function test_accept_transitions_sent_to_accepted(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->quotationService->markSent($quotation, $owner);
        $quotation->refresh();

        $response = $this->tenantPostJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/accept")
            ->assertOk();

        $this->assertSame('accepted', $response->json('data.status'));
    }

    public function test_accept_on_already_accepted_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->quotationService->accept($quotation, $owner);
        $quotation->refresh();

        $this->tenantPostJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/accept")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'quotations.invalid_transition');
    }

    // ── transitions: reject ───────────────────────────────────────────────────

    public function test_reject_transitions_sent_to_rejected(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->quotationService->markSent($quotation, $owner);
        $quotation->refresh();

        $response = $this->tenantPostJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/reject")
            ->assertOk();

        $this->assertSame('rejected', $response->json('data.status'));
    }

    public function test_reject_on_already_rejected_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $quotation = $this->createQuotation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->quotationService->reject($quotation, $owner);
        $quotation->refresh();

        $this->tenantPostJson($tenant, $owner, "/api/v1/quotations/{$quotation->id}/reject")
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'quotations.invalid_transition');
    }

    // ── multi-tenant boundary ─────────────────────────────────────────────────

    public function test_user_from_other_tenant_cannot_view_quotation(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $quotationA = $this->createQuotation($tenantA, $ownerA);

        $response = $this->tenantGetJson($tenantB, $ownerB, "/api/v1/quotations/{$quotationA->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_from_other_tenant_cannot_update_quotation(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $quotationA = $this->createQuotation($tenantA, $ownerA);

        $response = $this->tenantPutJson($tenantB, $ownerB, "/api/v1/quotations/{$quotationA->id}", [
            'issue_date' => now()->toDateString(),
            'items' => $this->defaultItems(),
        ]);
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_from_other_tenant_cannot_delete_quotation(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $quotationA = $this->createQuotation($tenantA, $ownerA);

        $response = $this->tenantDeleteJson($tenantB, $ownerB, "/api/v1/quotations/{$quotationA->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_from_other_tenant_cannot_transition_quotation(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $quotationA = $this->createQuotation($tenantA, $ownerA);

        $response = $this->tenantPostJson($tenantB, $ownerB, "/api/v1/quotations/{$quotationA->id}/send");
        $this->assertContains($response->status(), [403, 404]);
    }
}
