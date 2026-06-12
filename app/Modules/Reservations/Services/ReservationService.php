<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationPayment;
use App\Modules\Reservations\Models\ReservationStatusHistory;
use App\Modules\Reservations\Repositories\ReservationRepositoryInterface;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use DateTimeInterface;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Orchestrates the reservation status state machine, history timeline, and
 * the flexible deposit / partial-payment lifecycle.
 *
 * This service owns the transition rules. All status changes MUST go through
 * transitionTo() — direct $reservation->update(['status' => ...]) calls bypass
 * the history log and break the audit trail.
 *
 * Payment invariants (E3):
 *   - deposit_paid_cents is always recomputed as SUM(reservation_payments.amount_cents)
 *     after every recordPayment() call; it is never blindly incremented. This prevents
 *     drift if payments are deleted or corrected outside the normal flow.
 *   - Payments may not push deposit_paid_cents above total_cents (overpayment guard).
 *   - confirm() is the deposit-aware wrapper around transitionTo('confirmed'). Plain
 *     transitionTo() remains available for callers that don't need the deposit rule.
 *
 * State machine transitions:
 *   inquiry     → confirmed | cancelled
 *   confirmed   → in_progress | cancelled
 *   in_progress → ready | cancelled
 *   ready       → delivered | cancelled
 *   delivered   → (terminal)
 *   cancelled   → (terminal)
 *
 * Every transition is recorded in reservation_status_history (append-only).
 * The initial creation row (from_status=null) is written by recordInitialHistory()
 * which E5's store endpoint will call after persisting the new reservation.
 */
final readonly class ReservationService
{
    public function __construct(
        private OrderService $orderService,
        private ReservationRepositoryInterface $reservations,
    ) {}

    /**
     * Valid next statuses for each status.
     *
     * Terminal statuses (delivered, cancelled) map to empty arrays — no transitions
     * are allowed from them. Unknown statuses are rejected before this map is consulted.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'inquiry'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['in_progress', 'cancelled'],
        'in_progress' => ['ready', 'cancelled'],
        'ready'       => ['delivered', 'cancelled'],
        'delivered'   => [],
        'cancelled'   => [],
    ];

    /**
     * Capture a new reservation for the current tenant.
     *
     * Runs inside a DB transaction so the sequence claim + row insert are atomic.
     * The reservation starts in 'inquiry' status; recordInitialHistory() writes the
     * birth entry into reservation_status_history.
     *
     * deposit_required_cents handling:
     *   - If explicitly provided in $data (non-null), that value is used as-is —
     *     allowing staff to override the per-reservation deposit amount at capture time.
     *   - If not provided (null / absent), computeDepositRequired() derives the default
     *     from the tenant's reservation_deposit_pct.
     *
     * @param  array<string, mixed>  $data  Validated payload from StoreReservationRequest
     *
     * @throws DomainException When no tenant context can be resolved
     */
    public function capture(array $data, ?User $actor = null): Reservation
    {
        $tenant = $this->resolveTenant();

        return DB::transaction(function () use ($data, $actor, $tenant): Reservation {
            $number = $this->reservations->nextReservationNumber($tenant);

            // Use the explicit override when provided, otherwise apply the tenant default.
            $depositRequired = isset($data['deposit_required_cents'])
                ? (int) $data['deposit_required_cents']
                : $this->computeDepositRequired((int) ($data['total_cents'] ?? 0), $tenant);

            $reservation = $this->reservations->create([
                'tenant_id'              => $tenant->id,
                'branch_id'              => $data['branch_id'] ?? null,
                'customer_id'            => $data['customer_id'] ?? null,
                'reservation_number'     => $number,
                'description'            => $data['description'],
                'occasion'               => $data['occasion'] ?? null,
                'event_date'             => $data['event_date'] ?? null,
                'total_cents'            => (int) ($data['total_cents'] ?? 0),
                'deposit_required_cents' => $depositRequired,
                'deposit_paid_cents'     => 0,
                'status'                 => 'inquiry',
                'special_instructions'   => $data['special_instructions'] ?? null,
                'admin_notes'            => $data['admin_notes'] ?? null,
                'created_by'             => $actor?->id,
            ]);

            $this->recordInitialHistory($reservation, $actor);

            Log::info('Reservation captured', [
                'reservation_id'         => $reservation->id,
                'reservation_number'     => $reservation->reservation_number,
                'tenant_id'              => $reservation->tenant_id,
                'total_cents'            => $reservation->total_cents,
                'deposit_required_cents' => $reservation->deposit_required_cents,
                'actor_id'               => $actor?->id,
            ]);

            return $reservation;
        });
    }

    /**
     * Resolve the current tenant from the service container.
     *
     * Prefers the container-bound currentTenant (HTTP context). Falls back to a
     * direct find via the Auth facade for queue / CLI context.
     *
     * @throws DomainException When no tenant context can be resolved
     */
    private function resolveTenant(): Tenant
    {
        /** @var Tenant|null $current */
        $current = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($current instanceof Tenant) {
            return $current;
        }

        throw new DomainException('No active tenant context found. Cannot capture reservation.');
    }

    /**
     * Advance the reservation to a new status, recording the transition in the history.
     *
     * Validates the transition against the state machine map. Throws DomainException
     * for any invalid move — including unknown status values and terminal states.
     *
     * The DB write (status update + history row) is wrapped in a transaction so that
     * a partial write can never leave the reservation in an inconsistent state.
     *
     * @throws DomainException When the transition is not allowed by the state machine
     */
    public function transitionTo(
        Reservation $reservation,
        string $toStatus,
        ?User $actor = null,
        ?string $note = null,
    ): Reservation {
        $fromStatus = $reservation->status;

        $this->assertTransitionAllowed(reservation: $reservation, toStatus: $toStatus);

        DB::transaction(function () use ($reservation, $fromStatus, $toStatus, $actor, $note): void {
            $reservation->update(['status' => $toStatus]);

            ReservationStatusHistory::create([
                'tenant_id' => $reservation->tenant_id,
                'reservation_id' => $reservation->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'user_id' => $actor?->id,
                'note' => $note,
            ]);
        });

        Log::info('Reservation status transitioned', [
            'reservation_id' => $reservation->id,
            'reservation_number' => $reservation->reservation_number,
            'tenant_id' => $reservation->tenant_id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'actor_id' => $actor?->id,
        ]);

        return $reservation->fresh()->load('statusHistory');
    }

    /**
     * Return the list of valid next statuses for the reservation's current status.
     *
     * Returns an empty array for terminal statuses (delivered, cancelled).
     * The frontend uses this to render only the valid action buttons.
     *
     * @return list<string>
     */
    public function allowedTransitions(Reservation $reservation): array
    {
        return self::TRANSITIONS[$reservation->status] ?? [];
    }

    /**
     * Cancel a reservation.
     *
     * The friendly pre-checks here produce targeted error messages before
     * delegating to transitionTo(). This preserves the exact exception messages
     * that callers and tests depend on.
     *
     * @throws DomainException When the reservation is already cancelled or delivered
     */
    public function cancel(Reservation $reservation, ?User $user = null): void
    {
        if ($reservation->status === 'cancelled') {
            throw new DomainException(
                "Reservation #{$reservation->reservation_number} is already cancelled."
            );
        }

        if ($reservation->status === 'delivered') {
            throw new DomainException(
                "Cannot cancel reservation #{$reservation->reservation_number} — it has already been delivered. "
                .'Use a return/refund flow instead.'
            );
        }

        $this->transitionTo(reservation: $reservation, toStatus: 'cancelled', actor: $user);

        Log::info('Reservation cancelled', [
            'reservation_id' => $reservation->id,
            'reservation_number' => $reservation->reservation_number,
            'tenant_id' => $reservation->tenant_id,
            'cancelled_by' => $user?->id,
        ]);
    }

    /**
     * Write the initial history row when a reservation is first created.
     *
     * Called by the store endpoint (E5) immediately after persisting the new
     * reservation. from_status=null signals that this is the birth of the
     * reservation into its initial status, not a transition from a prior state.
     *
     * Idempotent by convention: the store flow calls this exactly once.
     * The test suite uses it directly to exercise the initial-history write path.
     */
    public function recordInitialHistory(Reservation $reservation, ?User $actor = null): void
    {
        ReservationStatusHistory::create([
            'tenant_id' => $reservation->tenant_id,
            'reservation_id' => $reservation->id,
            'from_status' => null,
            'to_status' => $reservation->status,
            'user_id' => $actor?->id,
            'note' => 'Reserva creada',
        ]);

        Log::info('Reservation initial history recorded', [
            'reservation_id' => $reservation->id,
            'reservation_number' => $reservation->reservation_number,
            'tenant_id' => $reservation->tenant_id,
            'initial_status' => $reservation->status,
            'actor_id' => $actor?->id,
        ]);
    }

    /**
     * Compute the default deposit amount for a given total and tenant configuration.
     *
     * Pure calculation — no side-effects, no DB writes. Used as the DEFAULT when
     * a reservation is confirmed and no explicit per-reservation override was set.
     *
     * Result is rounded to the nearest centavo (integer). The tenant's
     * reservation_deposit_pct is expected to be an integer percentage (e.g. 30 = 30%).
     */
    public function computeDepositRequired(int $totalCents, Tenant $tenant): int
    {
        return (int) round($totalCents * $tenant->reservation_deposit_pct / 100);
    }

    /**
     * Record a partial payment against a reservation and recompute the running deposit total.
     *
     * deposit_paid_cents is always authoritative-recomputed as the SUM of all payments
     * for the reservation after each insert — never incremented from the previous value.
     * This prevents drift if rows are corrected or deleted outside the normal flow.
     *
     * @throws InvalidArgumentException When $amountCents is not positive
     * @throws InvalidArgumentException When $paymentMethod is not one of cash|card|transfer|other
     * @throws DomainException          When the new total paid would exceed the reservation total
     */
    public function recordPayment(
        Reservation $reservation,
        int $amountCents,
        string $paymentMethod,
        ?User $actor = null,
        ?string $reference = null,
        ?DateTimeInterface $paidAt = null,
    ): ReservationPayment {
        if ($amountCents <= 0) {
            throw new InvalidArgumentException(
                "Payment amount must be greater than zero, got {$amountCents} centavos."
            );
        }

        $validMethods = ['cash', 'card', 'transfer', 'other'];

        if (! in_array($paymentMethod, $validMethods, strict: true)) {
            throw new InvalidArgumentException(
                "Invalid payment method '{$paymentMethod}'. Must be one of: "
                .implode(', ', $validMethods).'.'
            );
        }

        // Use the authoritative SUM from the DB rather than the cached column value.
        // The cached deposit_paid_cents can be out of sync (e.g. a manual correction),
        // and the overpayment guard must agree with what recordPayment() will compute
        // after the insert. This ensures the guard and the recompute are consistent.
        $currentPaidSum = (int) ReservationPayment::where('reservation_id', $reservation->id)
            ->sum('amount_cents');

        $projectedPaid = $currentPaidSum + $amountCents;

        if ($projectedPaid > $reservation->total_cents) {
            $outstanding = $reservation->total_cents - $currentPaidSum;

            throw new DomainException(
                "Payment of {$amountCents} would exceed the reservation total. "
                ."Outstanding balance is {$outstanding}."
            );
        }

        return DB::transaction(function () use (
            $reservation, $amountCents, $paymentMethod, $actor, $reference, $paidAt
        ): ReservationPayment {
            $payment = ReservationPayment::create([
                'tenant_id'      => $reservation->tenant_id,
                'reservation_id' => $reservation->id,
                'amount_cents'   => $amountCents,
                'payment_method' => $paymentMethod,
                'reference'      => $reference,
                'recorded_by'    => $actor?->id,
                'paid_at'        => $paidAt ?? now(),
            ]);

            // Authoritative recompute — sum all payments rather than incrementing
            // the previous value. Guards against drift from external corrections.
            $sumPaid = ReservationPayment::where('reservation_id', $reservation->id)->sum('amount_cents');

            $reservation->update(['deposit_paid_cents' => (int) $sumPaid]);

            Log::info('Reservation payment recorded', [
                'reservation_id'       => $reservation->id,
                'reservation_number'   => $reservation->reservation_number,
                'tenant_id'            => $reservation->tenant_id,
                'payment_id'           => $payment->id,
                'amount_cents'         => $amountCents,
                'payment_method'       => $paymentMethod,
                'deposit_paid_cents'   => (int) $sumPaid,
                'total_cents'          => $reservation->total_cents,
                'actor_id'             => $actor?->id,
            ]);

            return $payment;
        });
    }

    /**
     * Override the required deposit amount for a specific reservation.
     *
     * Staff may lower or raise the deposit threshold case-by-case (e.g. a small
     * flower arrangement needs only 10% while a large wedding requires 50%).
     * The new value must be in [0, total_cents] — requiring more than the full
     * reservation total does not make business sense.
     *
     * @throws InvalidArgumentException When $depositRequiredCents is negative
     * @throws DomainException          When $depositRequiredCents exceeds total_cents
     */
    public function setDepositRequired(
        Reservation $reservation,
        int $depositRequiredCents,
        ?User $actor = null,
    ): Reservation {
        if ($depositRequiredCents < 0) {
            throw new InvalidArgumentException(
                "Required deposit cannot be negative, got {$depositRequiredCents}."
            );
        }

        if ($depositRequiredCents > $reservation->total_cents) {
            throw new DomainException(
                "Required deposit ({$depositRequiredCents}) cannot exceed the reservation total "
                ."({$reservation->total_cents}) for reservation #{$reservation->reservation_number}."
            );
        }

        $reservation->update(['deposit_required_cents' => $depositRequiredCents]);

        Log::info('Reservation deposit requirement updated', [
            'reservation_id'         => $reservation->id,
            'reservation_number'     => $reservation->reservation_number,
            'tenant_id'              => $reservation->tenant_id,
            'deposit_required_cents' => $depositRequiredCents,
            'actor_id'               => $actor?->id,
        ]);

        return $reservation->fresh();
    }

    /**
     * Confirm a reservation, enforcing the deposit-coverage business rule.
     *
     * This is the deposit-aware wrapper around transitionTo('confirmed'). Plain
     * transitionTo($reservation, 'confirmed') remains available for callers that
     * bypass the deposit rule intentionally (e.g. admin bulk-confirm scripts).
     *
     * Behaviour:
     *   - If deposit_required_cents is 0 (not yet set), it is auto-populated via
     *     computeDepositRequired() before the coverage check runs — so confirming
     *     a fresh reservation always applies the tenant's default percentage.
     *   - If deposit_paid_cents < deposit_required_cents the transition is blocked
     *     and a DomainException is thrown, UNLESS $force = true (admin override).
     *   - Delegates to transitionTo() for the actual status change + history write.
     *
     * @throws DomainException When deposit is not covered and $force is false
     * @throws DomainException When the underlying transitionTo() rejects the transition
     */
    public function confirm(
        Reservation $reservation,
        ?User $actor = null,
        bool $force = false,
        ?string $note = null,
    ): Reservation {
        // Auto-set the default deposit requirement when none was overridden.
        if ($reservation->deposit_required_cents === 0) {
            $tenant = $this->resolveTenantForReservation($reservation);
            $defaultDeposit = $this->computeDepositRequired($reservation->total_cents, $tenant);
            $reservation->update(['deposit_required_cents' => $defaultDeposit]);
            $reservation->refresh();
        }

        if (! $force && $reservation->deposit_paid_cents < $reservation->deposit_required_cents) {
            $required = $reservation->deposit_required_cents;
            $paid     = $reservation->deposit_paid_cents;

            throw new DomainException(
                "Reservation #{$reservation->reservation_number} cannot be confirmed: "
                ."deposit of {$required} required, only {$paid} paid. Override to force."
            );
        }

        return $this->transitionTo(
            reservation: $reservation,
            toStatus: 'confirmed',
            actor: $actor,
            note: $note,
        );
    }

    /**
     * Convert a delivered (or ready-to-deliver) reservation into an Order.
     *
     * Idempotent: if converted_order_id is already set, throw — the conversion
     * already happened and duplicating it would corrupt financial reports.
     *
     * Module boundary: this method passes only Order-domain primitives to
     * OrderService::createFromReservation(). The Orders module never sees a
     * Reservation type — the dependency flows one way (Reservations → Orders).
     *
     * payment_status derivation (cents-level arithmetic):
     *   - paid    : deposit_paid_cents >= total_cents AND total_cents > 0
     *   - partial : deposit_paid_cents > 0 (but not fully paid)
     *   - pending : deposit_paid_cents == 0 (or total_cents == 0)
     *
     * Branch resolution: uses $reservation->branch if set; falls back to the
     * tenant's main branch. A reservation with no branch and no main branch in
     * the tenant is a data-integrity error — throw rather than guess.
     *
     * @throws DomainException When the reservation was already converted
     * @throws DomainException When the reservation is cancelled
     * @throws DomainException When no branch is available to hold the order
     */
    public function convertToOrder(Reservation $reservation, ?User $actor = null): Order
    {
        if ($reservation->converted_order_id !== null) {
            throw new DomainException(
                "Reservation #{$reservation->reservation_number} has already been converted to an order."
            );
        }

        if ($reservation->status === 'cancelled') {
            throw new DomainException(
                "Cannot convert reservation #{$reservation->reservation_number} to an order — it is cancelled."
            );
        }

        $branch = $this->resolveBranchForReservation($reservation);

        $paymentStatus = $this->derivePaymentStatus(
            totalCents: $reservation->total_cents,
            depositPaidCents: $reservation->deposit_paid_cents,
        );

        return DB::transaction(function () use ($reservation, $branch, $paymentStatus, $actor): Order {
            $order = $this->orderService->createFromReservation(
                branch: $branch,
                totalCents: $reservation->total_cents,
                customer: $reservation->customer,
                paymentStatus: $paymentStatus,
                user: $actor,
                notes: "Converted from reservation {$reservation->reservation_number}",
            );

            $reservation->update(['converted_order_id' => $order->id]);

            if ($reservation->status !== 'delivered') {
                // Bypass the normal state machine — converting to an Order IS the delivery
                // event. The state machine guards incremental transitions; this orchestrated
                // action delivers the reservation atomically regardless of which step it was
                // in (confirmed, in_progress, ready, etc.). The history row captures the jump.
                $fromStatus = $reservation->status;
                $reservation->update(['status' => 'delivered']);

                ReservationStatusHistory::create([
                    'tenant_id'      => $reservation->tenant_id,
                    'reservation_id' => $reservation->id,
                    'from_status'    => $fromStatus,
                    'to_status'      => 'delivered',
                    'user_id'        => $actor?->id,
                    'note'           => 'Entregada y convertida a pedido',
                ]);
            }

            Log::info('Reservation converted to order', [
                'reservation_id'     => $reservation->id,
                'reservation_number' => $reservation->reservation_number,
                'tenant_id'          => $reservation->tenant_id,
                'order_id'           => $order->id,
                'order_number'       => $order->order_number,
                'payment_status'     => $paymentStatus,
                'actor_id'           => $actor?->id,
            ]);

            return $order;
        });
    }

    /**
     * Derive the Order payment_status from what the customer already paid on the reservation.
     *
     * Returns 'paid' when the full amount was collected, 'partial' when something
     * was paid but not everything, and 'pending' when no payment was recorded at all.
     * A zero-total reservation is always 'pending' — there is nothing to mark as paid.
     */
    private function derivePaymentStatus(int $totalCents, int $depositPaidCents): string
    {
        if ($totalCents > 0 && $depositPaidCents >= $totalCents) {
            return 'paid';
        }

        if ($depositPaidCents > 0) {
            return 'partial';
        }

        return 'pending';
    }

    /**
     * Resolve the branch for a reservation, falling back to the tenant's main branch.
     *
     * @throws DomainException When neither the reservation's branch nor a main branch exists
     */
    private function resolveBranchForReservation(Reservation $reservation): Branch
    {
        if ($reservation->branch_id !== null) {
            // Always load fresh in case the relation was not eager-loaded
            $branch = $reservation->branch;

            if ($branch !== null) {
                return $branch;
            }
        }

        // Fall back to the tenant's main branch — covers reservations captured
        // before branch assignment was made mandatory, or single-branch tenants.
        $mainBranch = Branch::where('tenant_id', $reservation->tenant_id)
            ->where('is_main', true)
            ->first();

        if ($mainBranch === null) {
            throw new DomainException(
                "No branch available to convert reservation #{$reservation->reservation_number}. "
                .'Assign a branch to the reservation or configure a main branch for the tenant.'
            );
        }

        return $mainBranch;
    }

    /**
     * Resolve the Tenant for a reservation.
     *
     * Prefers the container-bound currentTenant (HTTP context). Falls back to a
     * direct find by tenant_id for CLI / queue context — same pattern as
     * OrderService::resolveTenant().
     */
    private function resolveTenantForReservation(Reservation $reservation): Tenant
    {
        /** @var Tenant|null $current */
        $current = app()->bound('currentTenant') ? app('currentTenant') : null;

        if ($current instanceof Tenant) {
            return $current;
        }

        $tenant = Tenant::find($reservation->tenant_id);

        if ($tenant === null) {
            throw new DomainException(
                "Cannot resolve tenant for reservation #{$reservation->reservation_number}."
            );
        }

        return $tenant;
    }

    /**
     * Assert that $toStatus is a valid next step from the reservation's current status.
     *
     * @throws DomainException When $toStatus is unknown or not reachable from current status
     */
    private function assertTransitionAllowed(Reservation $reservation, string $toStatus): void
    {
        if (! array_key_exists($toStatus, self::TRANSITIONS)) {
            throw new DomainException(
                "'{$toStatus}' is not a recognised reservation status."
            );
        }

        $allowed = self::TRANSITIONS[$reservation->status] ?? null;

        // Current status is not in the map — should not happen with clean data, but be safe
        if ($allowed === null) {
            throw new DomainException(
                "Reservation #{$reservation->reservation_number} has an unrecognised status '{$reservation->status}'."
            );
        }

        if (! in_array($toStatus, $allowed, strict: true)) {
            throw new DomainException(
                "Cannot transition reservation #{$reservation->reservation_number} from '{$reservation->status}' to '{$toStatus}'."
            );
        }
    }
}
