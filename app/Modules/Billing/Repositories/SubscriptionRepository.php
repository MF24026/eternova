<?php

declare(strict_types=1);

namespace App\Modules\Billing\Repositories;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cross-tenant subscription queries for the billing crons (recurring charges, dunning,
 * suspension, soft/hard delete, reconciliation). These iterate ALL tenants, so they
 * intentionally do not apply a tenant filter.
 *
 * Note: Subscription is a SaaS-platform model and does NOT use the BelongsToTenant global
 * scope (billing is cross-tenant by nature), so unlike business models there is no scope
 * to bypass here. The crons that call these methods run via the scheduler only — there is
 * no HTTP entry point that could leak one tenant's subscriptions to another.
 *
 * @return Collection<int, Subscription>
 */
final class SubscriptionRepository
{
    /**
     * Active subscriptions whose next charge is due on/before $asOf. Bounded below by a
     * 7-day lookback so a cron outage doesn't sweep up months of stale rows at once.
     *
     * @return Collection<int, Subscription>
     */
    public function dueForRecurringCharge(CarbonInterface $asOf): Collection
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->whereNotNull('next_billing_at')
            ->where('next_billing_at', '<=', $asOf)
            ->where('next_billing_at', '>', $asOf->copy()->subDays(7))
            ->get();
    }

    /**
     * All subscriptions in a given state, optionally only those untouched since $olderThan.
     * Used by suspend/soft-delete/hard-delete crons.
     *
     * @return Collection<int, Subscription>
     */
    public function inState(SubscriptionStatus $status, ?CarbonInterface $olderThan = null): Collection
    {
        $query = Subscription::query()->where('status', $status->value);

        if ($olderThan !== null) {
            $query->where('updated_at', '<', $olderThan);
        }

        return $query->get();
    }

    /**
     * Past-due subscriptions whose next dunning retry is due on/before $asOf and which
     * still have retries left (cap enforced by the caller via $maxRetries).
     *
     * @return Collection<int, Subscription>
     */
    public function dueForDunningRetry(CarbonInterface $asOf, int $maxRetries): Collection
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->where('retry_count', '<', $maxRetries)
            ->whereNotNull('next_retry_at')
            ->where('next_retry_at', '<=', $asOf)
            ->get();
    }
}
