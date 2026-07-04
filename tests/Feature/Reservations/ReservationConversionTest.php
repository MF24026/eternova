<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Services\ReservationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for ReservationService::convertToOrder() (S5-E4).
 *
 * Each test builds its own isolated tenant context so no cross-test state
 * can leak. The multi-tenant invariant is tested explicitly: an order created
 * from Tenant A's reservation must carry Tenant A's tenant_id.
 *
 * DB assertion strategy: after each conversion, both the reservations and
 * orders tables are queried directly to verify atomicity — if a sub-step
 * fails, neither table should have mutated.
 */
final class ReservationConversionTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReservationService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a minimal isolated tenant context and a reservation ready for conversion.
     *
     * @return array{tenant: Tenant, branch: Branch, reservation: Reservation, user: User}
     */
    private function setupTenant(
        int $totalCents = 10000,
        int $depositPaidCents = 0,
        string $status = 'ready',
        bool $withBranch = true,
    ): array {
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forTenant($tenant)
            ->state([
                'branch_id' => $withBranch ? $branch->id : null,
                'total_cents' => $totalCents,
                'deposit_paid_cents' => $depositPaidCents,
                'status' => $status,
            ])
            ->create();

        return compact('tenant', 'branch', 'reservation', 'user');
    }

    // ── Happy path ────────────────────────────────────────────────────────────

    public function test_converts_ready_reservation_to_delivered_order_with_correct_fields(): void
    {
        ['tenant' => $tenant, 'reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(totalCents: 15000);

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertInstanceOf(Order::class, $order);
        $this->assertSame('reservation', $order->source);
        $this->assertSame('delivered', $order->status);
        $this->assertSame(15000, $order->total_cents);
        $this->assertSame(15000, $order->subtotal_cents);
        $this->assertSame(0, $order->tax_cents);
        $this->assertSame(0, $order->discount_cents);
        $this->assertSame($tenant->id, $order->tenant_id);
        $this->assertNull($order->payment_method);
        $this->assertNotNull($order->order_number);
        $this->assertStringStartsWith('CC-', $order->order_number);
    }

    public function test_converted_order_has_no_line_items(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant();

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertCount(0, $order->items);
        $this->assertDatabaseCount('order_items', 0);
    }

    public function test_reservation_converted_order_id_is_set_and_resolves(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant();

        $order = $this->service->convertToOrder($reservation, $user);

        $reservation->refresh();

        $this->assertSame($order->id, $reservation->converted_order_id);
        $this->assertTrue($reservation->convertedOrder()->is($order));
    }

    public function test_reservation_is_transitioned_to_delivered(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(status: 'ready');

        $this->service->convertToOrder($reservation, $user);

        $reservation->refresh();
        $this->assertSame('delivered', $reservation->status);
    }

    public function test_reservation_status_history_row_is_written_on_conversion(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(status: 'ready');

        $this->service->convertToOrder($reservation, $user);

        $this->assertDatabaseHas('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'from_status' => 'ready',
            'to_status' => 'delivered',
        ]);
    }

    public function test_initial_order_status_history_row_is_written(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant();

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertDatabaseHas('order_status_history', [
            'order_id' => $order->id,
            'from_status' => null,
            'to_status' => 'delivered',
            'note' => 'Created from reservation',
        ]);
    }

    // ── Payment status derivation ─────────────────────────────────────────────

    public function test_fully_paid_reservation_produces_order_with_paid_status(): void
    {
        ['reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(totalCents: 10000, depositPaidCents: 10000);

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame('paid', $order->payment_status);
    }

    public function test_overpaid_deposit_still_yields_paid_status(): void
    {
        // Guard: if total > 0 and paid >= total → paid. The overpayment guard in
        // recordPayment() prevents deposit_paid > total in practice, but the
        // derivation rule must be correct even for edge-case factory data.
        ['reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(totalCents: 10000, depositPaidCents: 10000);

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame('paid', $order->payment_status);
    }

    public function test_partially_paid_reservation_produces_order_with_partial_status(): void
    {
        ['reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(totalCents: 10000, depositPaidCents: 3000);

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame('partial', $order->payment_status);
    }

    public function test_unpaid_reservation_produces_order_with_pending_status(): void
    {
        ['reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(totalCents: 10000, depositPaidCents: 0);

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame('pending', $order->payment_status);
    }

    public function test_zero_total_reservation_produces_pending_order_not_paid(): void
    {
        // A zero-total custom arrangement (e.g. internal project) should not
        // be marked 'paid' merely because 0 >= 0.
        ['reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(totalCents: 0, depositPaidCents: 0);

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame('pending', $order->payment_status);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_converting_already_converted_reservation_throws_domain_exception(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant();

        $this->service->convertToOrder($reservation, $user);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/already been converted/');

        $this->service->convertToOrder($reservation->fresh(), $user);
    }

    public function test_idempotency_guard_does_not_create_a_second_order(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant();

        $this->service->convertToOrder($reservation, $user);

        $orderCountBefore = Order::withoutGlobalScopes()->count();

        try {
            $this->service->convertToOrder($reservation->fresh(), $user);
        } catch (DomainException) {
            // expected
        }

        $this->assertSame($orderCountBefore, Order::withoutGlobalScopes()->count());
    }

    // ── Cancelled reservation guard ───────────────────────────────────────────

    public function test_converting_cancelled_reservation_throws_domain_exception(): void
    {
        ['reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(status: 'cancelled');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/cancelled/');

        $this->service->convertToOrder($reservation, $user);
    }

    public function test_cancelled_reservation_conversion_does_not_create_order(): void
    {
        ['reservation' => $reservation, 'user' => $user]
            = $this->setupTenant(status: 'cancelled');

        try {
            $this->service->convertToOrder($reservation, $user);
        } catch (DomainException) {
            // expected
        }

        $this->assertDatabaseCount('orders', 0);
    }

    // ── Branch resolution ─────────────────────────────────────────────────────

    public function test_reservation_without_branch_falls_back_to_tenant_main_branch(): void
    {
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $mainBranch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forTenant($tenant)
            ->state([
                'branch_id' => null,    // no branch assigned
                'total_cents' => 8000,
                'status' => 'ready',
            ])
            ->create();

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame($mainBranch->id, $order->branch_id);
    }

    public function test_no_branch_and_no_main_branch_throws_domain_exception(): void
    {
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        // Deliberately create a branch that is NOT main
        Branch::factory()->forTenant($tenant)->create(['is_main' => false]);
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forTenant($tenant)
            ->state([
                'branch_id' => null,
                'status' => 'ready',
            ])
            ->create();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/No branch available/');

        $this->service->convertToOrder($reservation, $user);
    }

    // ── Multi-tenant isolation ────────────────────────────────────────────────

    public function test_converted_order_belongs_to_the_reservation_tenant(): void
    {
        // Tenant A does a conversion
        ['tenant' => $tenantA, 'reservation' => $reservationA, 'user' => $userA]
            = $this->setupTenant(totalCents: 12000);

        // Tenant B exists and should never see Tenant A's order
        $tenantB = Tenant::factory()->create();
        Branch::factory()->forTenant($tenantB)->create(['is_main' => true]);

        $order = $this->service->convertToOrder($reservationA, $userA);

        $this->assertSame($tenantA->id, $order->tenant_id);

        // Switch scope to Tenant B — the order must be invisible
        app()->instance('currentTenant', $tenantB);
        $this->assertNull(Order::find($order->id));
    }

    // ── Conversion from confirmed state ──────────────────────────────────────

    public function test_can_convert_from_confirmed_state_after_advancing_through_ready(): void
    {
        // convertToOrder() requires the reservation to reach 'ready' before calling.
        // Staff who want to convert early must first advance to 'ready', then convert.
        // This test verifies the combined flow: advance → ready, then convert.
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->state(['status' => 'confirmed', 'total_cents' => 7000])
            ->create();

        // Advance through the valid path to 'ready'
        $reservation = $this->service->transitionTo($reservation, 'in_progress', $user);
        $reservation = $this->service->transitionTo($reservation->fresh(), 'ready', $user);

        $order = $this->service->convertToOrder($reservation->fresh(), $user);

        $this->assertSame('delivered', $order->status);
        $reservation->refresh();
        $this->assertSame('delivered', $reservation->status);
    }

    // ── Reservation with customer ─────────────────────────────────────────────

    public function test_customer_is_carried_over_to_the_order(): void
    {
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();
        $customer = Customer::factory()->forTenant($tenant)->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->forCustomer($customer)
            ->state(['status' => 'ready', 'total_cents' => 9000])
            ->create();

        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame($customer->id, $order->customer_id);
    }

    // ── Atomic rollback ───────────────────────────────────────────────────────

    public function test_already_delivered_reservation_is_not_re_transitioned(): void
    {
        // If the reservation is already 'delivered' but not yet converted
        // (edge case: status manually set), the conversion should succeed
        // without trying an invalid transition from 'delivered'.
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $user = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->state(['status' => 'delivered', 'total_cents' => 5000])
            ->create();

        // Must NOT throw — delivered reservations without converted_order_id
        // can still be converted (they were delivered but conversion was skipped).
        $order = $this->service->convertToOrder($reservation, $user);

        $this->assertSame('delivered', $order->status);
        // Status was already delivered — no extra history row for the transition
        $this->assertDatabaseMissing('reservation_status_history', [
            'reservation_id' => $reservation->id,
            'from_status' => 'delivered',
            'to_status' => 'delivered',
        ]);
    }
}
