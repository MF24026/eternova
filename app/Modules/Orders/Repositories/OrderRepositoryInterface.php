<?php

declare(strict_types=1);

namespace App\Modules\Orders\Repositories;

use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrderRepositoryInterface
{
    /**
     * Persist a new Order row.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Order;

    /**
     * Find an order by its ULID within the current tenant scope.
     * Returns null when not found (caller decides whether to 404 or handle gracefully).
     */
    public function find(string $id): ?Order;

    /**
     * Paginated list of orders scoped to the current tenant.
     *
     * Supported filters:
     *   - branch_id    string — limit to a specific branch
     *   - status       string — one of the status enum values
     *   - customer_id  int    — customer filter
     *   - date_from    string — ISO date, inclusive lower bound on created_at
     *   - date_to      string — ISO date, inclusive upper bound on created_at
     *   - search       string — LIKE match on order_number
     *   - per_page     int    — page size, default 20, max 100
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Order>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Generate and claim the next order number for a tenant in the current year.
     *
     * Must be called INSIDE an active DB::transaction() — it uses a FOR UPDATE lock
     * on the order_sequences row to serialise concurrent POS sales for the same tenant.
     *
     * Format: CC-{year}-{zero-padded-4-digit-seq}  e.g. CC-2026-0001
     */
    public function nextOrderNumber(Tenant $tenant): string;

    /**
     * Count orders per status for the current tenant, honoring filters EXCEPT status.
     *
     * Always returns all six known statuses (zero-filled when no orders exist for
     * a given status). The status filter is explicitly excluded so that the counts
     * represent the full distribution regardless of which tab is selected.
     *
     * Supported filters (same as paginate, minus status):
     *   - branch_id    string
     *   - customer_id  int
     *   - date_from    string — ISO date
     *   - date_to      string — ISO date
     *   - search       string — order_number LIKE
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>   status => count
     */
    public function statusCounts(array $filters): array;
}
