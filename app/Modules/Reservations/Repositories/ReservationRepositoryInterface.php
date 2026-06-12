<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Repositories;

use App\Modules\Reservations\Models\Reservation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ReservationRepositoryInterface
{
    /**
     * Persist a new Reservation row.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Reservation;

    /**
     * Find a reservation by its integer id within the current tenant scope.
     * Returns null when not found (caller decides whether to 404 or handle gracefully).
     */
    public function find(int $id): ?Reservation;

    /**
     * Paginated list of reservations scoped to the current tenant.
     *
     * Supported filters:
     *   - branch_id    string — limit to a specific branch
     *   - status       string — one of the status enum values
     *   - customer_id  int    — customer filter
     *   - date_from    string — ISO date, inclusive lower bound on event_date
     *   - date_to      string — ISO date, inclusive upper bound on event_date
     *   - search       string — LIKE match on reservation_number
     *   - per_page     int    — page size, default 20, max 100
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Reservation>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Generate and claim the next reservation number for a tenant in the current year.
     *
     * Must be called INSIDE an active DB::transaction() — it uses a FOR UPDATE lock
     * on the reservation_sequences row to serialise concurrent captures for the same tenant.
     *
     * Format: RSV-{year}-{zero-padded-4-digit-seq}  e.g. RSV-2026-0001
     */
    public function nextReservationNumber(Tenant $tenant): string;

    /**
     * Count reservations per status for the current tenant, honoring filters EXCEPT status.
     *
     * Always returns all six known statuses (zero-filled when no reservations exist for
     * a given status). The status filter is explicitly excluded so that the counts
     * represent the full distribution regardless of which tab is selected.
     *
     * Supported filters (same as paginate, minus status):
     *   - branch_id    string
     *   - customer_id  int
     *   - date_from    string — ISO date (on event_date)
     *   - date_to      string — ISO date (on event_date)
     *   - search       string — reservation_number LIKE
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>   status => count
     */
    public function statusCounts(array $filters): array;
}
