<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Jobs;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Models\QuotationStatusHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Expire all quotations whose valid_until date has passed.
 *
 * Runs daily via the scheduler. Marks every quotation with
 * status IN ('draft', 'sent') AND valid_until < today as 'expired'.
 *
 * Tenant-context design:
 *   Scheduled/queued jobs run outside a normal HTTP request — there is no
 *   EnsureTenant middleware and no 'currentTenant' bound in the container.
 *   We CANNOT call QuotationService::transitionTo() here because:
 *     (1) transitionTo() returns $quotation->fresh()->load('statusHistory'),
 *         which works fine, but more importantly:
 *     (2) QuotationService::create() and some helpers call $this->resolveTenant()
 *         which throws DomainException in the absence of currentTenant.
 *     (3) transitionTo() itself does NOT call resolveTenant() — it is safe
 *         to call. HOWEVER, the constructor now receives an OrderService dep,
 *         and resolving QuotationService via the container from within a job
 *         is fragile: any future constructor addition to either service could
 *         silently misbehave in a context-free queue worker.
 *
 *   Decision: replicate the minimal DB write directly in this job (status update
 *   + history row). The logic is trivial (two SQL writes), and this keeps the job
 *   100% context-free with no risk of accidentally invoking service methods that
 *   assume currentTenant is bound. This mirrors the ProcessReceiptOcrJob pattern
 *   (fetches with withoutGlobalScopes, operates on the raw rows).
 *
 * Expiry rule:
 *   valid_until strictly before today. A quotation whose valid_until is TODAY
 *   is still valid (the day is not over). We compare < today (not <=).
 *
 * history row:
 *   user_id = null (system transition — no human actor).
 *   note = 'Vencida automaticamente' (Spanish, user-facing label).
 *   tenant_id is copied from each quotation so the row satisfies the
 *   BelongsToTenant scope when later read back under a tenant context.
 */
final class ExpireQuotationsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 120];

    public function handle(): void
    {
        $today = Carbon::today();

        // withoutGlobalScopes() bypasses BelongsToTenant — we intentionally want
        // ALL tenants' quotations, not just a single tenant's.
        $expirable = Quotation::withoutGlobalScopes()
            ->whereIn('status', ['draft', 'sent'])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $today->toDateString())
            ->get(['id', 'tenant_id', 'quotation_number', 'status']);

        if ($expirable->isEmpty()) {
            Log::info('ExpireQuotationsJob: no quotations to expire', [
                'today' => $today->toDateString(),
            ]);

            return;
        }

        $expiredCount = 0;

        foreach ($expirable as $quotation) {
            DB::transaction(function () use ($quotation): void {
                // Direct update bypasses BelongsToTenant scope — intentional.
                Quotation::withoutGlobalScopes()
                    ->where('id', $quotation->id)
                    ->update(['status' => 'expired']);

                // Write the history row directly, mirroring what transitionTo() does.
                // user_id = null signals this is a system (automated) transition.
                QuotationStatusHistory::insert([
                    'tenant_id' => $quotation->tenant_id,
                    'quotation_id' => $quotation->id,
                    'from_status' => $quotation->status,
                    'to_status' => 'expired',
                    'user_id' => null,
                    'note' => 'Vencida automaticamente',
                    'created_at' => now(),
                ]);
            });

            $expiredCount++;
        }

        Log::info('ExpireQuotationsJob: expired quotations processed', [
            'expired_count' => $expiredCount,
            'today' => $today->toDateString(),
        ]);
    }
}
