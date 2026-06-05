<?php

declare(strict_types=1);

namespace App\Modules\Billing\Observers;

use App\Modules\Billing\Models\Subscription;

final class SubscriptionObserver
{
    /** @var list<string> */
    private const BLOCKING_STATUSES = ['active', 'trialing'];

    /**
     * Prevent creating a second active-or-trialing subscription for the same tenant.
     *
     * MySQL does not support partial unique indexes, so this invariant lives here.
     * Even if a caller bypasses SubscriptionService (tinker, seeder, legacy code),
     * the Observer fires and throws before Eloquent issues the INSERT.
     */
    public function creating(Subscription $sub): void
    {
        if (! in_array($sub->status, self::BLOCKING_STATUSES, strict: true)) {
            return;
        }

        $this->assertNoConflict($sub->tenant_id, excludeId: null);
    }

    /**
     * Prevent reactivating a subscription when the tenant already has another active-or-trialing one.
     *
     * Only runs when `status` is being changed TO a blocking state (e.g. reactivation flows,
     * Wompi webhook setting status = 'active' on a previously-canceled row).
     */
    public function updating(Subscription $sub): void
    {
        if (! $sub->isDirty('status')) {
            return;
        }

        if (! in_array($sub->status, self::BLOCKING_STATUSES, strict: true)) {
            return;
        }

        $this->assertNoConflict($sub->tenant_id, excludeId: $sub->id);
    }

    /**
     * @param  int|string|null  $excludeId  The subscription being updated (excluded from the conflict check).
     */
    private function assertNoConflict(int|string $tenantId, int|string|null $excludeId): void
    {
        $query = Subscription::where('tenant_id', $tenantId)
            ->whereIn('status', self::BLOCKING_STATUSES);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        if (! $query->exists()) {
            return;
        }

        throw new \DomainException(
            "Tenant [{$tenantId}] already has an active or trialing subscription. ".
            'Cancel or expire it before creating a new one.'
        );
    }
}
