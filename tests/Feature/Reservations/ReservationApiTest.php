<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Services\ReservationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the Reservations Admin API (S5-E5).
 *
 * Verifies:
 *   - index returns paginated reservations scoped to current tenant only
 *   - index status_counts spans all six statuses and ignores the status filter
 *   - index filters (branch_id, status, date_range, search) work correctly
 *   - show returns full detail (payments + timeline); cross-tenant id → 404
 *   - store creates reservation with number + initial history
 *   - transition valid/invalid (422); 'confirmed' uses deposit-aware path
 *   - recordPayment updates balance; overpayment returns 422
 *   - convert creates linked order; idempotency returns 422
 *   - cancel transitions; already-cancelled returns 422
 *   - settings GET returns defaults; PUT persists and is tenant-scoped
 *   - auth gates: 401 unauthenticated, 403 customer role
 *
 * Each test creates its own isolated tenant context — no shared state that
 * could cause cross-test pollution or flaky ordering.
 */
final class ReservationApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private ReservationService $reservationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reservationService = app(ReservationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal tenant with a branch and an owner user.
     *
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    /**
     * Capture a reservation via the service (so it has a proper status history row).
     *
     * @param  array<string, mixed>  $overrides
     */
    private function captureReservation(Tenant $tenant, User $actor, array $overrides = []): Reservation
    {
        app()->instance('currentTenant', $tenant);

        return $this->reservationService->capture(
            data: array_merge([
                'description' => 'Arreglo floral para boda',
                'occasion' => 'Boda',
                'total_cents' => 25000,
            ], $overrides),
            actor: $actor,
        );
    }

    // ── index: auth gates ─────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->tenantUrl($tenant, 'api/v1/reservations'))
            ->assertStatus(401);
    }

    public function test_customer_role_cannot_list_reservations(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $customer = User::factory()->forTenant($tenant, role: 'customer')->create();

        $this->tenantGetJson($tenant, $customer, '/api/v1/reservations')
            ->assertStatus(403);
    }

    // ── index: pagination + tenant isolation ─────────────────────────────────

    public function test_index_returns_paginated_reservations_for_current_tenant_only(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();

        $this->captureReservation($tenantA, $ownerA);
        $this->captureReservation($tenantA, $ownerA);

        // Create 3 reservations for tenant B — must not appear in tenant A's response
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();
        $this->captureReservation($tenantB, $ownerB);
        $this->captureReservation($tenantB, $ownerB);
        $this->captureReservation($tenantB, $ownerB);

        $response = $this->tenantGetJson($tenantA, $ownerA, '/api/v1/reservations')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id'],
                'status_counts',
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    // ── index: status_counts ──────────────────────────────────────────────────

    public function test_index_status_counts_returns_all_six_statuses(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/reservations')
            ->assertOk();

        $counts = $response->json('status_counts');

        $this->assertArrayHasKey('inquiry', $counts);
        $this->assertArrayHasKey('confirmed', $counts);
        $this->assertArrayHasKey('in_progress', $counts);
        $this->assertArrayHasKey('ready', $counts);
        $this->assertArrayHasKey('delivered', $counts);
        $this->assertArrayHasKey('cancelled', $counts);
    }

    public function test_index_status_counts_are_correct_values(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        $this->captureReservation($tenant, $owner);  // inquiry
        $this->captureReservation($tenant, $owner);  // inquiry
        Reservation::factory()->forBranch($branch)->create(['status' => 'confirmed']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/reservations')
            ->assertOk();

        $counts = $response->json('status_counts');

        $this->assertSame(2, $counts['inquiry']);
        $this->assertSame(1, $counts['confirmed']);
        $this->assertSame(0, $counts['in_progress']);
    }

    public function test_index_status_counts_ignore_the_status_filter(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        $this->captureReservation($tenant, $owner);  // inquiry
        Reservation::factory()->forBranch($branch)->create(['status' => 'confirmed']);

        // Filter by status=inquiry → data only has inquiry reservations,
        // but counts must still include confirmed=1
        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/reservations?status=inquiry')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));

        $counts = $response->json('status_counts');
        $this->assertSame(1, $counts['inquiry']);
        $this->assertSame(1, $counts['confirmed']);
    }

    // ── index: filters ────────────────────────────────────────────────────────

    public function test_index_filter_by_branch_id(): void
    {
        ['tenant' => $tenant, 'branch' => $branchA, 'owner' => $owner] = $this->setupTenant();

        $branchB = Branch::factory()->forTenant($tenant)->create();
        app()->instance('currentTenant', $tenant);

        // Reservation for branch A
        $this->reservationService->capture(
            data: ['description' => 'Arreglo A', 'total_cents' => 5000, 'branch_id' => $branchA->id],
            actor: $owner,
        );

        // Reservation for branch B
        app()->instance('currentTenant', $tenant);
        $this->reservationService->capture(
            data: ['description' => 'Arreglo B', 'total_cents' => 5000, 'branch_id' => $branchB->id],
            actor: $owner,
        );

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/reservations?branch_id={$branchA->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_filter_by_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);
        $this->captureReservation($tenant, $owner);  // inquiry
        Reservation::factory()->forBranch($branch)->create(['status' => 'confirmed']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/reservations?status=confirmed')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('confirmed', $response->json('data.0.status'));
    }

    public function test_index_filter_by_date_range(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        Reservation::factory()->forBranch($branch)->create([
            'event_date' => now()->subDays(30)->toDateString(),
        ]);
        Reservation::factory()->forBranch($branch)->create([
            'event_date' => now()->addDays(7)->toDateString(),
        ]);

        $from = now()->toDateString();
        $to = now()->addDays(14)->toDateString();

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/reservations?date_from={$from}&date_to={$to}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
    }

    public function test_index_search_by_reservation_number(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        app()->instance('currentTenant', $tenant);

        Reservation::factory()->forBranch($branch)->create(['reservation_number' => 'RSV-2026-0042']);
        Reservation::factory()->forBranch($branch)->create(['reservation_number' => 'RSV-2026-0099']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/reservations?search=0042')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('RSV-2026-0042', $response->json('data.0.reservation_number'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_reservation_with_payments_and_timeline(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->reservationService->recordPayment(
            reservation: $reservation,
            amountCents: 5000,
            paymentMethod: 'cash',
            actor: $owner,
        );

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/reservations/{$reservation->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'reservation_number',
                    'status',
                    'total_cents',
                    'deposit_paid_cents',
                    'balance_cents',
                    'deposit_outstanding_cents',
                    'allowed_transitions',
                    'payments',
                    'status_history',
                ],
            ]);

        $this->assertNotEmpty($response->json('data.payments'));
        $this->assertNotEmpty($response->json('data.status_history'));
    }

    public function test_show_cross_tenant_reservation_id_is_not_accessible(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $reservationB = $this->captureReservation($tenantB, $ownerB);

        // Tenant A user tries to access tenant B's reservation.
        // BelongsToTenant scope hides the resource → 404.
        $response = $this->tenantGetJson($tenantA, $ownerA, "/api/v1/reservations/{$reservationB->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_store_creates_reservation_with_number_and_initial_history(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/reservations', [
            'description' => 'Arreglo floral para boda',
            'occasion' => 'Boda',
            'total_cents' => 20000,
        ])->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'reservation_number',
                    'status',
                    'total_cents',
                    'deposit_required_cents',
                    'allowed_transitions',
                    'status_history',
                ],
            ]);

        $this->assertSame('inquiry', $response->json('data.status'));
        $this->assertStringStartsWith('RSV-', (string) $response->json('data.reservation_number'));
        $this->assertNotEmpty($response->json('data.status_history'));
    }

    public function test_store_uses_deposit_required_override_when_provided(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/reservations', [
            'description' => 'Arreglo especial',
            'total_cents' => 50000,
            'deposit_required_cents' => 5000,  // explicit override: 10%
        ])->assertStatus(201);

        // The explicit override (5000) must be used, not the tenant default (30% = 15000)
        $this->assertSame(5000, $response->json('data.deposit_required_cents'));
    }

    public function test_store_uses_tenant_default_deposit_pct_when_no_override(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $tenant->update(['reservation_deposit_pct' => 30]);

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/reservations', [
            'description' => 'Arreglo sin override',
            'total_cents' => 10000,
        ])->assertStatus(201);

        // 30% of 10000 = 3000
        $this->assertSame(3000, $response->json('data.deposit_required_cents'));
    }

    // ── transition ────────────────────────────────────────────────────────────

    public function test_transition_advances_reservation_status(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner, ['total_cents' => 10000]);

        // Fully pay so confirm() passes deposit check
        app()->instance('currentTenant', $tenant);
        $this->reservationService->recordPayment($reservation, 10000, 'cash', $owner);
        $reservation->refresh();

        $response = $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/status",
            ['status' => 'confirmed'],
        )->assertOk();

        $this->assertSame('confirmed', $response->json('data.status'));
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_transition_force_confirms_without_deposit(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner, ['total_cents' => 10000]);
        // No payment made

        $response = $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/status",
            ['status' => 'confirmed', 'force' => true],
        )->assertOk();

        $this->assertSame('confirmed', $response->json('data.status'));
    }

    public function test_invalid_transition_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);
        // 'inquiry' → 'delivered' is not a valid transition

        $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/status",
            ['status' => 'delivered'],
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'reservations.invalid_transition');

        $this->assertSame('inquiry', $reservation->fresh()->status);
    }

    public function test_transition_with_note_stores_note_in_history(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner, ['total_cents' => 10000]);

        app()->instance('currentTenant', $tenant);
        $this->reservationService->recordPayment($reservation, 10000, 'cash', $owner);
        $reservation->refresh();

        $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/status",
            ['status' => 'confirmed', 'note' => 'adelanto recibido en efectivo'],
        )->assertOk();

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'to_status' => 'confirmed',
            'note' => 'adelanto recibido en efectivo',
        ]);
    }

    // ── recordPayment ─────────────────────────────────────────────────────────

    public function test_record_payment_updates_deposit_paid_and_balance(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner, ['total_cents' => 20000]);

        $response = $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/payments",
            ['amount_cents' => 6000, 'payment_method' => 'cash'],
        )->assertOk();

        $this->assertSame(6000, $response->json('data.deposit_paid_cents'));
        $this->assertSame(14000, $response->json('data.balance_cents'));

        $this->assertDatabaseHas('reservation_payments', [
            'reservation_id' => $reservation->id,
            'amount_cents' => 6000,
            'payment_method' => 'cash',
        ]);
    }

    public function test_overpayment_returns_422(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner, ['total_cents' => 10000]);

        $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/payments",
            ['amount_cents' => 99999, 'payment_method' => 'card'],
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'reservations.payment_rejected');

        // deposit_paid_cents must remain 0
        $this->assertSame(0, $reservation->fresh()->deposit_paid_cents);
    }

    // ── convert ───────────────────────────────────────────────────────────────

    public function test_convert_creates_linked_order(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);

        $response = $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/convert",
        )->assertOk()
            ->assertJsonStructure([
                'data' => ['order_id', 'order_number'],
            ]);

        $orderId = $response->json('data.order_id');
        $this->assertNotNull($orderId);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'converted_order_id' => $orderId,
        ]);
    }

    public function test_convert_returns_422_on_double_conversion(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->reservationService->convertToOrder($reservation, $owner);

        $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/convert",
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'reservations.cannot_convert');
    }

    // ── cancel ────────────────────────────────────────────────────────────────

    public function test_cancel_endpoint_cancels_the_reservation(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);

        $response = $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/cancel",
        )->assertOk();

        $this->assertSame('cancelled', $response->json('data.status'));
        $this->assertSame('cancelled', $reservation->fresh()->status);
    }

    public function test_cancel_returns_422_when_already_cancelled(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->reservationService->cancel($reservation, $owner);
        $reservation->refresh();

        $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/cancel",
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'reservations.cannot_cancel');
    }

    public function test_cancel_returns_422_when_already_delivered(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);

        app()->instance('currentTenant', $tenant);
        $this->reservationService->convertToOrder($reservation, $owner);
        // After convert, reservation is delivered
        $reservation->refresh();
        $this->assertSame('delivered', $reservation->status);

        $this->tenantPostJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/cancel",
        )->assertStatus(422)
            ->assertJsonPath('error_code', 'reservations.cannot_cancel');
    }

    // ── settings ──────────────────────────────────────────────────────────────

    public function test_settings_show_returns_deposit_pct_and_occasions(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $tenant->update(['reservation_deposit_pct' => 25]);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/reservations/settings')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['deposit_pct', 'occasions'],
            ]);

        $this->assertSame(25, $response->json('data.deposit_pct'));
        $this->assertIsArray($response->json('data.occasions'));
        $this->assertNotEmpty($response->json('data.occasions'));
    }

    public function test_settings_show_returns_default_occasions_when_tenant_has_none(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $tenant->update(['reservation_occasions' => null]);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/reservations/settings')
            ->assertOk();

        $occasions = $response->json('data.occasions');
        $this->assertContains('Boda', $occasions);
        $this->assertContains('Otro', $occasions);
    }

    public function test_settings_update_persists_deposit_pct_and_occasions(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $response = $this->tenantPutJson($tenant, $owner, '/api/v1/reservations/settings', [
            'deposit_pct' => 40,
            'occasions' => ['Boda', 'Cumpleanos', 'Corporativo'],
        ])->assertOk();

        $this->assertSame(40, $response->json('data.deposit_pct'));
        $this->assertSame(['Boda', 'Cumpleanos', 'Corporativo'], $response->json('data.occasions'));

        $tenant->refresh();
        $this->assertSame(40, $tenant->reservation_deposit_pct);
    }

    public function test_settings_update_is_tenant_scoped(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        // Tenant A updates its settings
        $this->tenantPutJson($tenantA, $ownerA, '/api/v1/reservations/settings', [
            'deposit_pct' => 50,
        ])->assertOk();

        // Tenant B's settings must not be affected
        $tenantB->refresh();
        $this->assertSame(30, $tenantB->reservation_deposit_pct);
    }

    public function test_staff_can_read_settings_but_cannot_update(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        // Staff can read (needed to populate the occasions dropdown)
        $this->tenantGetJson($tenant, $staff, '/api/v1/reservations/settings')
            ->assertOk();

        // Staff cannot write
        $this->tenantPutJson($tenant, $staff, '/api/v1/reservations/settings', [
            'deposit_pct' => 99,
        ])->assertStatus(403);
    }

    // ── response structure ────────────────────────────────────────────────────

    public function test_index_response_includes_correct_envelope(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantGetJson($tenant, $owner, '/api/v1/reservations')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links',
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id', 'request_id'],
                'status_counts',
            ]);
    }

    public function test_show_response_includes_allowed_transitions(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $reservation = $this->captureReservation($tenant, $owner);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/reservations/{$reservation->id}")
            ->assertOk();

        // 'inquiry' → allowed: ['confirmed', 'cancelled']
        $allowed = $response->json('data.allowed_transitions');
        $this->assertContains('confirmed', $allowed);
        $this->assertContains('cancelled', $allowed);
    }

    public function test_show_with_customer_returns_customer_data(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $customer = Customer::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);
        $reservation = $this->reservationService->capture(
            data: [
                'description' => 'Con cliente',
                'total_cents' => 5000,
                'customer_id' => $customer->id,
            ],
            actor: $owner,
        );

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/reservations/{$reservation->id}")
            ->assertOk();

        $this->assertSame($customer->id, $response->json('data.customer.id'));
        $this->assertSame($customer->name, $response->json('data.customer.name'));
    }

    // ── multi-tenant boundary ─────────────────────────────────────────────────

    public function test_user_from_other_tenant_cannot_access_reservation(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $reservationA = $this->captureReservation($tenantA, $ownerA);

        $response = $this->tenantGetJson($tenantB, $ownerB, "/api/v1/reservations/{$reservationA->id}");
        $this->assertContains($response->status(), [403, 404]);
    }

    public function test_user_from_other_tenant_cannot_transition_reservation(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();

        $reservationA = $this->captureReservation($tenantA, $ownerA);

        $response = $this->tenantPatchJson(
            $tenantB, $ownerB,
            "/api/v1/reservations/{$reservationA->id}/status",
            ['status' => 'confirmed'],
        );
        $this->assertContains($response->status(), [403, 404]);
    }

    // ── assignee ───────────────────────────────────────────────────────────────

    public function test_assign_endpoint_assigns_a_tenant_member(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();
        $reservation = $this->captureReservation($tenant, $owner);

        $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/assignee",
            ['assigned_to' => $staff->id],
        )->assertOk()->assertJsonPath('data.assignee.id', $staff->id);

        $this->assertSame($staff->id, $reservation->fresh()->assigned_to);
    }

    public function test_assign_endpoint_can_unassign(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();
        $reservation = $this->captureReservation($tenant, $owner);
        $reservation->update(['assigned_to' => $staff->id]);

        $this->tenantPatchJson(
            $tenant, $owner,
            "/api/v1/reservations/{$reservation->id}/assignee",
            ['assigned_to' => null],
        )->assertOk();

        $this->assertNull($reservation->fresh()->assigned_to);
    }

    public function test_assign_endpoint_rejects_cross_tenant_assignee(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'owner' => $ownerB] = $this->setupTenant();
        $reservation = $this->captureReservation($tenantA, $ownerA);

        // ownerB belongs to tenant B — assigning them to tenant A's reservation must 422.
        $this->tenantPatchJson(
            $tenantA, $ownerA,
            "/api/v1/reservations/{$reservation->id}/assignee",
            ['assigned_to' => $ownerB->id],
        )->assertStatus(422)->assertJsonPath('error_code', 'reservations.invalid_assignment');

        $this->assertNull($reservation->fresh()->assigned_to);
    }
}
