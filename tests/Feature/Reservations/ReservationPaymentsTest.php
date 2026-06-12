<?php

declare(strict_types=1);

namespace Tests\Feature\Reservations;

use App\Models\User;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationPayment;
use App\Modules\Reservations\Services\ReservationService;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use DomainException;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for flexible deposits and partial payments (S5-E3).
 *
 * Covers: recordPayment(), setDepositRequired(), computeDepositRequired(),
 * confirm(), depositOutstandingCents(), and multi-tenant payment isolation.
 *
 * Each test is isolated: its own tenant/branch/reservation setup.
 * No shared class-level state that could cause cross-test pollution.
 */
final class ReservationPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReservationService::class);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    /**
     * Build an isolated tenant context and return a fresh reservation with known totals.
     *
     * @return array{tenant: Tenant, branch: Branch, reservation: Reservation, user: User}
     */
    private function setupTenant(
        int $totalCents = 10000,
        int $depositRequiredCents = 3000,
        int $depositPaidCents = 0,
        string $status = 'inquiry',
    ): array {
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user   = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->create([
                'total_cents'             => $totalCents,
                'deposit_required_cents'  => $depositRequiredCents,
                'deposit_paid_cents'      => $depositPaidCents,
                'status'                  => $status,
            ]);

        return compact('tenant', 'branch', 'reservation', 'user');
    }

    // ── recordPayment: happy path ─────────────────────────────────────────────

    public function test_record_payment_creates_payment_row_and_recomputes_deposit_paid(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(totalCents: 10000);

        $payment = $this->service->recordPayment(
            reservation: $reservation,
            amountCents: 3000,
            paymentMethod: 'cash',
            actor: $user,
        );

        $this->assertInstanceOf(ReservationPayment::class, $payment);
        $this->assertSame(3000, $payment->amount_cents);
        $this->assertSame('cash', $payment->payment_method);
        $this->assertSame($user->id, $payment->recorded_by);

        $reservation->refresh();
        $this->assertSame(3000, $reservation->deposit_paid_cents);
    }

    public function test_multiple_partial_payments_accumulate_correctly(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(totalCents: 10000);

        $this->service->recordPayment($reservation, 2000, 'cash', $user);
        $this->service->recordPayment($reservation->fresh(), 1500, 'transfer', $user);
        $this->service->recordPayment($reservation->fresh(), 500, 'card', $user);

        $reservation->refresh();
        $this->assertSame(4000, $reservation->deposit_paid_cents);

        $count = ReservationPayment::where('reservation_id', $reservation->id)->count();
        $this->assertSame(3, $count);
    }

    public function test_payment_deposit_paid_is_authoritative_sum_not_increment(): void
    {
        // Start with a pre-existing deposit_paid (simulating drift) and assert that
        // after recordPayment() the value is the clean SUM, not old_value + new_amount.
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 10000,
            depositPaidCents: 9999, // artificially high — not backed by any payment rows
        );

        // There are zero payment rows, so after insert the SUM should be 2000, not 9999 + 2000.
        $this->service->recordPayment($reservation, 2000, 'cash', $user);

        $reservation->refresh();
        $this->assertSame(2000, $reservation->deposit_paid_cents);
    }

    public function test_paying_exactly_up_to_total_is_allowed(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 5000,
            depositRequiredCents: 1500,
        );

        // Two instalments that reach exactly 5000
        $this->service->recordPayment($reservation, 3000, 'cash', $user);
        $this->service->recordPayment($reservation->fresh(), 2000, 'transfer', $user);

        $reservation->refresh();
        $this->assertSame(5000, $reservation->deposit_paid_cents);
        $this->assertSame(0, $reservation->remainingBalanceCents());
    }

    public function test_record_payment_persists_reference_and_paid_at(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000);

        $paidAt = now()->subHours(3);

        $payment = $this->service->recordPayment(
            reservation: $reservation,
            amountCents: 2000,
            paymentMethod: 'transfer',
            reference: 'TXN-9876',
            paidAt: $paidAt,
        );

        $this->assertSame('TXN-9876', $payment->reference);
        // Compare timestamps at second precision — microsecond rounding can differ after
        // the model is cast back from the DB (datetime columns store up to seconds only).
        $this->assertSame(
            $paidAt->format('Y-m-d H:i:s'),
            $payment->paid_at->format('Y-m-d H:i:s')
        );
    }

    public function test_record_payment_with_null_actor_sets_recorded_by_null(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000);

        $payment = $this->service->recordPayment($reservation, 1000, 'other', actor: null);

        $this->assertNull($payment->recorded_by);
    }

    // ── recordPayment: validation guards ─────────────────────────────────────

    public function test_record_payment_throws_for_zero_amount(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/greater than zero/');

        $this->service->recordPayment($reservation, 0, 'cash');
    }

    public function test_record_payment_throws_for_negative_amount(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000);

        $this->expectException(InvalidArgumentException::class);

        $this->service->recordPayment($reservation, -500, 'cash');
    }

    public function test_record_payment_throws_for_invalid_payment_method(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid payment method/');

        $this->service->recordPayment($reservation, 1000, 'bitcoin');
    }

    // ── recordPayment: overpayment guard ──────────────────────────────────────

    public function test_overpayment_beyond_total_throws_domain_exception(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 5000,
            depositPaidCents: 4000,
        );

        // Put an actual payment row so SUM matches the deposit_paid_cents value
        ReservationPayment::factory()->forReservation($reservation)->create(['amount_cents' => 4000]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/would exceed the reservation total/');

        $this->service->recordPayment($reservation, 1500, 'cash', $user);
    }

    public function test_overpayment_does_not_create_payment_row_or_mutate_deposit_paid(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(
            totalCents: 5000,
            depositPaidCents: 4500,
        );

        ReservationPayment::factory()->forReservation($reservation)->create(['amount_cents' => 4500]);

        $countBefore = ReservationPayment::where('reservation_id', $reservation->id)->count();

        try {
            $this->service->recordPayment($reservation, 1000, 'cash');
        } catch (DomainException) {
            // expected
        }

        $this->assertSame($countBefore, ReservationPayment::where('reservation_id', $reservation->id)->count());
        $this->assertSame(4500, $reservation->fresh()->deposit_paid_cents);
    }

    // ── setDepositRequired ────────────────────────────────────────────────────

    public function test_set_deposit_required_overrides_per_reservation(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 10000,
            depositRequiredCents: 3000,
        );

        $updated = $this->service->setDepositRequired($reservation, 5000, $user);

        $this->assertSame(5000, $updated->deposit_required_cents);
        $this->assertSame(5000, $reservation->fresh()->deposit_required_cents);
    }

    public function test_set_deposit_required_allows_zero(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000, depositRequiredCents: 3000);

        $updated = $this->service->setDepositRequired($reservation, 0);

        $this->assertSame(0, $updated->deposit_required_cents);
    }

    public function test_set_deposit_required_allows_full_total(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000, depositRequiredCents: 3000);

        $updated = $this->service->setDepositRequired($reservation, 10000);

        $this->assertSame(10000, $updated->deposit_required_cents);
    }

    public function test_set_deposit_required_rejects_negative(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cannot be negative/');

        $this->service->setDepositRequired($reservation, -1);
    }

    public function test_set_deposit_required_rejects_amount_exceeding_total(): void
    {
        ['reservation' => $reservation] = $this->setupTenant(totalCents: 10000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/cannot exceed the reservation total/');

        $this->service->setDepositRequired($reservation, 10001);
    }

    // ── computeDepositRequired ────────────────────────────────────────────────

    public function test_compute_deposit_required_uses_tenant_pct(): void
    {
        $tenant30 = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $tenant50 = Tenant::factory()->create(['reservation_deposit_pct' => 50]);

        $this->assertSame(3000, $this->service->computeDepositRequired(10000, $tenant30));
        $this->assertSame(5000, $this->service->computeDepositRequired(10000, $tenant50));
    }

    public function test_compute_deposit_required_rounds_to_nearest_centavo(): void
    {
        // 30% of 99 = 29.7 → rounds to 30
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);

        $this->assertSame(30, $this->service->computeDepositRequired(99, $tenant));
    }

    public function test_compute_deposit_required_different_tenants_produce_different_results(): void
    {
        $tenantA = Tenant::factory()->create(['reservation_deposit_pct' => 20]);
        $tenantB = Tenant::factory()->create(['reservation_deposit_pct' => 40]);

        $depositA = $this->service->computeDepositRequired(10000, $tenantA);
        $depositB = $this->service->computeDepositRequired(10000, $tenantB);

        $this->assertSame(2000, $depositA);
        $this->assertSame(4000, $depositB);
        $this->assertNotSame($depositA, $depositB);
    }

    // ── confirm() ─────────────────────────────────────────────────────────────

    public function test_confirm_succeeds_when_deposit_is_covered(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 10000,
            depositRequiredCents: 3000,
            depositPaidCents: 3000,
            status: 'inquiry',
        );

        $confirmed = $this->service->confirm($reservation, $user);

        $this->assertSame('confirmed', $confirmed->status);
    }

    public function test_confirm_succeeds_when_paid_exceeds_required(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 10000,
            depositRequiredCents: 3000,
            depositPaidCents: 5000,
            status: 'inquiry',
        );

        $confirmed = $this->service->confirm($reservation, $user);

        $this->assertSame('confirmed', $confirmed->status);
    }

    public function test_confirm_blocks_when_deposit_not_covered(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 10000,
            depositRequiredCents: 3000,
            depositPaidCents: 1000,
            status: 'inquiry',
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be confirmed.*deposit.*required.*paid/i');

        $this->service->confirm($reservation, $user);
    }

    public function test_confirm_with_force_true_bypasses_deposit_rule(): void
    {
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 10000,
            depositRequiredCents: 3000,
            depositPaidCents: 0,
            status: 'inquiry',
        );

        // Force override — admin is allowed to confirm without the deposit
        $confirmed = $this->service->confirm($reservation, $user, force: true);

        $this->assertSame('confirmed', $confirmed->status);
    }

    public function test_confirm_auto_sets_deposit_required_when_zero(): void
    {
        // deposit_required_cents is 0 (no override set yet) → confirm() should
        // derive the default from the tenant's pct (30%) and then apply the rule.
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user   = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->create([
                'total_cents'            => 10000,
                'deposit_required_cents' => 0,
                'deposit_paid_cents'     => 0,
                'status'                 => 'inquiry',
            ]);

        // Without payment the deposit check will block — confirm with force to verify
        // that deposit_required_cents was auto-set to 3000 (30% of 10000).
        $this->service->confirm($reservation, $user, force: true);

        $reservation->refresh();
        $this->assertSame(3000, $reservation->deposit_required_cents);
    }

    public function test_confirm_auto_sets_deposit_then_blocks_when_not_covered(): void
    {
        // deposit_required_cents = 0, no payments → auto-set to 3000, then block.
        $tenant = Tenant::factory()->create(['reservation_deposit_pct' => 30]);
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user   = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $reservation = Reservation::factory()
            ->forBranch($branch)
            ->create([
                'total_cents'            => 10000,
                'deposit_required_cents' => 0,
                'deposit_paid_cents'     => 0,
                'status'                 => 'inquiry',
            ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/cannot be confirmed/');

        $this->service->confirm($reservation, $user, force: false);
    }

    public function test_plain_transition_to_confirmed_still_works_without_deposit_rule(): void
    {
        // The generic transitionTo() must remain callable for 'confirmed' without
        // triggering any deposit validation — it's the opener for the state machine.
        ['reservation' => $reservation, 'user' => $user] = $this->setupTenant(
            totalCents: 10000,
            depositRequiredCents: 9000,
            depositPaidCents: 0,
            status: 'inquiry',
        );

        // No deposit paid, but plain transitionTo() should not care
        $updated = $this->service->transitionTo($reservation, 'confirmed', $user);

        $this->assertSame('confirmed', $updated->status);
    }

    // ── depositOutstandingCents() model helper ────────────────────────────────

    public function test_deposit_outstanding_is_required_minus_paid_when_positive(): void
    {
        $this->setupTenant();

        $reservation = Reservation::factory()->create([
            'total_cents'            => 10000,
            'deposit_required_cents' => 3000,
            'deposit_paid_cents'     => 1000,
        ]);

        $this->assertSame(2000, $reservation->depositOutstandingCents());
    }

    public function test_deposit_outstanding_is_zero_when_fully_covered(): void
    {
        $this->setupTenant();

        $reservation = Reservation::factory()->create([
            'total_cents'            => 10000,
            'deposit_required_cents' => 3000,
            'deposit_paid_cents'     => 3000,
        ]);

        $this->assertSame(0, $reservation->depositOutstandingCents());
    }

    public function test_deposit_outstanding_never_goes_negative(): void
    {
        // Paid more than required (e.g. via manual correction) — clamp at zero
        $this->setupTenant();

        $reservation = Reservation::factory()->create([
            'total_cents'            => 10000,
            'deposit_required_cents' => 3000,
            'deposit_paid_cents'     => 5000,
        ]);

        $this->assertSame(0, $reservation->depositOutstandingCents());
    }

    // ── Multi-tenant isolation ────────────────────────────────────────────────

    public function test_payment_on_tenant_a_does_not_affect_tenant_b_deposit_paid(): void
    {
        // Tenant A setup
        $tenantA = Tenant::factory()->create();
        $branchA = Branch::factory()->forTenant($tenantA)->create();
        $userA   = User::factory()->forTenant($tenantA, role: 'staff')->create();

        app()->instance('currentTenant', $tenantA);

        $reservationA = Reservation::factory()
            ->forBranch($branchA)
            ->create(['total_cents' => 10000, 'deposit_required_cents' => 3000]);

        // Tenant B setup
        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantB);

        $reservationB = Reservation::factory()
            ->forBranch($branchB)
            ->create(['total_cents' => 10000, 'deposit_required_cents' => 3000]);

        // Record a payment on Tenant A's reservation
        app()->instance('currentTenant', $tenantA);
        $this->service->recordPayment($reservationA, 3000, 'cash', $userA);

        // Tenant B's reservation must be untouched
        $this->assertSame(0, $reservationB->fresh()->deposit_paid_cents);
    }

    public function test_payments_are_scoped_to_tenant(): void
    {
        // Tenant A gets 2 payments, Tenant B gets 1. Under each tenant's scope
        // only that tenant's payments are visible.
        $tenantA = Tenant::factory()->create();
        $branchA = Branch::factory()->forTenant($tenantA)->create();

        app()->instance('currentTenant', $tenantA);
        $resA = Reservation::factory()->forBranch($branchA)->create(['total_cents' => 20000, 'deposit_required_cents' => 5000]);
        ReservationPayment::factory()->forReservation($resA)->count(2)->create(['amount_cents' => 1000]);

        $tenantB = Tenant::factory()->create();
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantB);
        $resB = Reservation::factory()->forBranch($branchB)->create(['total_cents' => 10000, 'deposit_required_cents' => 2000]);
        ReservationPayment::factory()->forReservation($resB)->count(1)->create(['amount_cents' => 1000]);

        // Under Tenant B's scope only 1 payment visible
        $this->assertSame(1, ReservationPayment::count());

        // Switch to Tenant A's scope — only 2 payments visible
        app()->instance('currentTenant', $tenantA);
        $this->assertSame(2, ReservationPayment::count());
    }

    public function test_recompute_after_payment_sums_only_the_reservation_own_payments(): void
    {
        // Two reservations in the same tenant. Paying one must not bleed into the other.
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create();
        $user   = User::factory()->forTenant($tenant, role: 'staff')->create();

        app()->instance('currentTenant', $tenant);

        $resX = Reservation::factory()->forBranch($branch)->create(['total_cents' => 10000, 'deposit_required_cents' => 3000]);
        $resY = Reservation::factory()->forBranch($branch)->create(['total_cents' => 10000, 'deposit_required_cents' => 3000]);

        $this->service->recordPayment($resX, 2000, 'cash', $user);
        $this->service->recordPayment($resX->fresh(), 1000, 'transfer', $user);

        $this->assertSame(3000, $resX->fresh()->deposit_paid_cents);
        $this->assertSame(0, $resY->fresh()->deposit_paid_cents);
    }
}
