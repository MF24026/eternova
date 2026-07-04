<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Repositories;

use App\Modules\Reservations\Models\Reservation;
use App\Modules\Reservations\Models\ReservationSequence;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentReservationRepository implements ReservationRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Reservation
    {
        return Reservation::create($data);
    }

    public function find(int $id): ?Reservation
    {
        return Reservation::with(['branch', 'customer', 'assignee', 'creator'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Reservation>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = Reservation::query();

        if (isset($filters['branch_id']) && $filters['branch_id'] !== '') {
            $query->where('branch_id', (string) $filters['branch_id']);
        }

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['customer_id']) && $filters['customer_id'] !== null) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->whereDate('event_date', '>=', (string) $filters['date_from']);
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->whereDate('event_date', '<=', (string) $filters['date_to']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = (string) $filters['search'];
            $query->where('reservation_number', 'like', "%{$term}%");
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Atomically claim the next reservation number for the tenant + current year.
     *
     * Uses SELECT ... FOR UPDATE on the reservation_sequences row so that concurrent
     * captures for the same tenant are serialised at the DB level.
     * Whichever transaction acquires the lock first increments the counter and
     * commits; the second transaction then sees the already-incremented value and
     * picks the next one.
     *
     * MUST be called inside an active DB::transaction() — the lock is released
     * when the transaction commits or rolls back.
     */
    public function nextReservationNumber(Tenant $tenant): string
    {
        $year = (int) date('Y');

        // Lock the sequence row for this tenant+year. firstOrCreate is not safe
        // under race conditions here — we use updateOrCreate with a lock instead.
        // The lockForUpdate() call serialises concurrent callers.
        $sequence = ReservationSequence::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            // First reservation of the year for this tenant — create the sequence row.
            // We set last_sequence=1 directly rather than inserting 0 and then
            // incrementing, to avoid a second write.
            $sequence = ReservationSequence::create([
                'tenant_id' => $tenant->id,
                'year' => $year,
                'last_sequence' => 1,
            ]);

            return $this->formatReservationNumber($year, 1);
        }

        $next = $sequence->last_sequence + 1;
        $sequence->update(['last_sequence' => $next]);

        return $this->formatReservationNumber($year, $next);
    }

    /**
     * Count reservations per status for the current tenant, honoring all filters except status.
     *
     * A single aggregate query groups by status and returns counts for each.
     * Zero-filling ensures all six known statuses are always present in the result
     * even when there are no reservations in a given state.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function statusCounts(array $filters): array
    {
        $zero = [
            'inquiry' => 0,
            'confirmed' => 0,
            'in_progress' => 0,
            'ready' => 0,
            'delivered' => 0,
            'cancelled' => 0,
        ];

        $query = Reservation::query();

        // Apply the same filters as paginate() but explicitly skip 'status'.
        if (isset($filters['branch_id']) && $filters['branch_id'] !== '') {
            $query->where('branch_id', (string) $filters['branch_id']);
        }

        if (isset($filters['customer_id']) && $filters['customer_id'] !== null) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->whereDate('event_date', '>=', (string) $filters['date_from']);
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->whereDate('event_date', '<=', (string) $filters['date_to']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = (string) $filters['search'];
            $query->where('reservation_number', 'like', "%{$term}%");
        }

        // One DB round-trip: select status, count(*) group by status
        $counts = $query
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        // Cast to int and zero-fill missing statuses
        foreach ($counts as $status => $total) {
            if (array_key_exists($status, $zero)) {
                $zero[$status] = (int) $total;
            }
        }

        return $zero;
    }

    /**
     * Format: RSV-{year}-{zero-padded-4-digit-seq}
     *
     * Zero-padding to 4 digits means the format stays consistent up to 9999
     * reservations per tenant per year. Beyond that, the number grows naturally
     * (5-digit, 6-digit, …) without breaking uniqueness.
     */
    private function formatReservationNumber(int $year, int $seq): string
    {
        return sprintf('RSV-%d-%04d', $year, $seq);
    }
}
