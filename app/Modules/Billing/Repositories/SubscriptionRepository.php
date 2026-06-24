<?php

declare(strict_types=1);

namespace App\Modules\Billing\Repositories;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Cross-tenant subscription queries for the billing crons (suspension, soft/hard delete,
 * reconciliation). These iterate ALL tenants, so they intentionally do not apply a tenant
 * filter. Recurrence and dunning retries are owned by Wompi, not driven from here.
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
}
