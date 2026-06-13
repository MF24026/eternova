<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Repositories;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface QuotationRepositoryInterface
{
    /**
     * Persist a new Quotation row (without items — items are persisted separately).
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Quotation;

    /**
     * Persist updated scalar fields on an existing quotation.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Quotation $quotation, array $data): Quotation;

    /**
     * Find a quotation by its integer id within the current tenant scope.
     *
     * Returns null when not found (caller decides whether to 404 or handle gracefully).
     */
    public function find(int $id): ?Quotation;

    /**
     * Soft-delete the quotation.
     */
    public function delete(Quotation $quotation): void;

    /**
     * Return a paginated list of quotations for the current tenant, applying filters.
     *
     * Supported filter keys:
     *   status, customer_id, date_from, date_to (on issue_date), search, per_page.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Quotation>
     */
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Count quotations per status for the current tenant, honoring all filters except status.
     *
     * Zero-fills all five known statuses so the result always has the same shape.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function statusCounts(array $filters): array;

    /**
     * Generate and claim the next quotation number for a tenant in the current year.
     *
     * Must be called INSIDE an active DB::transaction() — it uses a FOR UPDATE lock
     * on the quotation_sequences row to serialise concurrent creates for the same tenant.
     *
     * Format: COT-{year}-{zero-padded-4-digit-seq}  e.g. COT-2026-0001
     */
    public function nextQuotationNumber(Tenant $tenant): string;
}
