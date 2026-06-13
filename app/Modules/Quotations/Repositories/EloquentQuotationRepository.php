<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Repositories;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationSequence;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;

final class EloquentQuotationRepository implements QuotationRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Quotation
    {
        return Quotation::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        $quotation->update($data);

        return $quotation->fresh();
    }

    public function find(int $id): ?Quotation
    {
        return Quotation::with(['branch', 'customer', 'assignee', 'creator', 'items'])
            ->find($id);
    }

    /**
     * Atomically claim the next quotation number for the tenant + current year.
     *
     * Uses SELECT ... FOR UPDATE on the quotation_sequences row so that concurrent
     * creates for the same tenant are serialised at the DB level.
     * Whichever transaction acquires the lock first increments the counter and
     * commits; the second transaction then sees the already-incremented value and
     * picks the next one.
     *
     * MUST be called inside an active DB::transaction() — the lock is released
     * when the transaction commits or rolls back.
     */
    public function nextQuotationNumber(Tenant $tenant): string
    {
        $year = (int) date('Y');

        // Lock the sequence row for this tenant+year. firstOrCreate is not safe
        // under race conditions here — we use lockForUpdate() to serialise concurrent callers.
        $sequence = QuotationSequence::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($sequence === null) {
            // First quotation of the year for this tenant — create the sequence row.
            // We set last_sequence=1 directly rather than inserting 0 and then
            // incrementing, to avoid a second write.
            QuotationSequence::create([
                'tenant_id'     => $tenant->id,
                'year'          => $year,
                'last_sequence' => 1,
            ]);

            return $this->formatQuotationNumber($year, 1);
        }

        $next = $sequence->last_sequence + 1;
        $sequence->update(['last_sequence' => $next]);

        return $this->formatQuotationNumber($year, $next);
    }

    /**
     * Format: COT-{year}-{zero-padded-4-digit-seq}
     *
     * Zero-padding to 4 digits keeps the format consistent up to 9999 quotations
     * per tenant per year. Beyond that the number grows naturally without breaking
     * uniqueness or the UNIQUE(tenant_id, quotation_number) constraint.
     */
    private function formatQuotationNumber(int $year, int $seq): string
    {
        return sprintf('COT-%d-%04d', $year, $seq);
    }
}
