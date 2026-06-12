<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Policies;

use App\Models\User;
use App\Modules\Auth\Policies\TenantScopedPolicy;
use App\Modules\Reservations\Models\Reservation;

/**
 * Gates reservation management operations to authenticated tenant members.
 *
 * Staff are included in all abilities because they need to:
 *   - viewAny: see the reservations list to track upcoming events
 *   - view: open a single reservation's detail for fulfilment or support
 *   - update: advance status, record payments, or cancel during operations
 *   - create: capture new reservations at the POS or storefront
 *
 * Settings operations (deposit_pct, occasions) are restricted to owner/admin —
 * those are tenant-level configuration that staff should not change.
 */
final class ReservationPolicy extends TenantScopedPolicy
{
    /**
     * Any tenant staff member may list reservations.
     */
    public function viewAny(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may view a single reservation.
     *
     * assertTenantMatches() also verifies the reservation belongs to the current
     * tenant — a cross-tenant reservation id resolves to null via BelongsToTenant
     * global scope and route-model binding, producing a natural 404 before
     * this policy is ever called. The check here is an additional safety net.
     */
    public function view(User $user, Reservation $reservation): bool
    {
        return $this->assertTenantMatches($user, $reservation)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may update a reservation (transition, payment, cancel).
     */
    public function update(User $user, Reservation $reservation): bool
    {
        return $this->assertTenantMatches($user, $reservation)
            && $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Any tenant staff member may capture a new reservation.
     */
    public function create(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin', 'staff']);
    }

    /**
     * Only owner and admin may change tenant-level reservation settings.
     */
    public function manageSettings(User $user): bool
    {
        return $this->assertHasRole($user, ['owner', 'admin']);
    }
}
