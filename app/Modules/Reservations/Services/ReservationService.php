<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Services;

use App\Models\User;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationStatusHistory;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the reservation status state machine and history timeline.
 *
 * This service owns the transition rules. All status changes MUST go through
 * transitionTo() — direct $reservation->update(['status' => ...]) calls bypass
 * the history log and break the audit trail.
 *
 * The service intentionally has no constructor dependencies in E2: it writes to
 * the Reservation and ReservationStatusHistory models directly. Heavier
 * dependencies (OrderService for conversion, sequences for numbering) will be
 * injected in later epics (E4, E5) when this service grows.
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
