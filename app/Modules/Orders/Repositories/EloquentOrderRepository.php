<?php

declare(strict_types=1);

namespace App\Modules\Orders\Repositories;

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderSequence;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentOrderRepository implements OrderRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Order
    {
        return Order::create($data);
    }

    public function find(string $id): ?Order
    {
        return Order::with(['items', 'branch', 'customer', 'user'])->find($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Order>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = Order::with(['items', 'customer', 'user']);

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
            $query->whereDate('created_at', '>=', (string) $filters['date_from']);
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', (string) $filters['date_to']);
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Atomically claim the next order number for the tenant + current year.
     *
     * Uses SELECT ... FOR UPDATE on the order_sequences row so that concurrent
     * POS transactions for the same tenant are serialised at the DB level.
     * Whichever transaction acquires the lock first increments the counter and
     * commits; the second transaction then sees the already-incremented value and
     * picks the next one.
     *
     * MUST be called inside an active DB::transaction() — the lock is released
     * when the transaction commits or rolls back.
     */
    public function nextOrderNumber(Tenant $tenant): string
    {
        $year = (int) date('Y');

        // Lock the sequence row for this tenant+year. firstOrCreate is not safe
        // under race conditions here — we use updateOrCreate with a lock instead.
        // The lockForUpdate() call serialises concurrent callers.
        $sequence = OrderSequence::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            // First order of the year for this tenant — create the sequence row.
            // We set last_sequence=1 directly rather than inserting 0 and then
            // incrementing, to avoid a second write.
            $sequence = OrderSequence::create([
                'tenant_id' => $tenant->id,
                'year' => $year,
                'last_sequence' => 1,
            ]);

            return $this->formatOrderNumber($year, 1);
        }

        $next = $sequence->last_sequence + 1;
        $sequence->update(['last_sequence' => $next]);

        return $this->formatOrderNumber($year, $next);
    }

    /**
     * Format: CC-{year}-{zero-padded-4-digit-seq}
     *
     * Zero-padding to 4 digits means the format stays consistent up to 9999
     * orders per tenant per year. Beyond that, the number grows naturally
     * (5-digit, 6-digit, …) without breaking uniqueness.
     */
    private function formatOrderNumber(int $year, int $seq): string
    {
        return sprintf('CC-%d-%04d', $year, $seq);
    }
}
