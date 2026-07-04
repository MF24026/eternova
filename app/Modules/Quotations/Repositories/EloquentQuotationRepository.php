<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Repositories;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationSequence;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    public function delete(Quotation $quotation): void
    {
        $quotation->delete();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<Quotation>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 20)));

        $query = Quotation::query();

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['customer_id']) && $filters['customer_id'] !== null && $filters['customer_id'] !== '') {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->whereDate('issue_date', '>=', (string) $filters['date_from']);
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->whereDate('issue_date', '<=', (string) $filters['date_to']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = (string) $filters['search'];
            $query->where(static function ($q) use ($term): void {
                $q->where('quotation_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', static function ($cq) use ($term): void {
                        $cq->where('name', 'like', "%{$term}%");
                    });
            });
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * Count quotations per status, honoring all filters except status.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function statusCounts(array $filters): array
    {
        $zero = [
            'draft' => 0,
            'sent' => 0,
            'accepted' => 0,
            'rejected' => 0,
            'expired' => 0,
        ];

        $query = Quotation::query();

        if (isset($filters['customer_id']) && $filters['customer_id'] !== null && $filters['customer_id'] !== '') {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if (isset($filters['date_from']) && $filters['date_from'] !== '') {
            $query->whereDate('issue_date', '>=', (string) $filters['date_from']);
        }

        if (isset($filters['date_to']) && $filters['date_to'] !== '') {
            $query->whereDate('issue_date', '<=', (string) $filters['date_to']);
        }

        if (isset($filters['search']) && $filters['search'] !== '') {
            $term = (string) $filters['search'];
            $query->where(static function ($q) use ($term): void {
                $q->where('quotation_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', static function ($cq) use ($term): void {
                        $cq->where('name', 'like', "%{$term}%");
                    });
            });
        }

        $counts = $query
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        foreach ($counts as $status => $total) {
            if (array_key_exists($status, $zero)) {
                $zero[$status] = (int) $total;
            }
        }

        return $zero;
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
                'tenant_id' => $tenant->id,
                'year' => $year,
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
