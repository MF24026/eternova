<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Domain\Events\SubscriptionStateChanged;
use App\Modules\Billing\Models\BillingAuditLog;

/**
 * Turns every subscription state change into an append-only audit row. Runs
 * synchronously so the trail is written in the same transaction as the transition —
 * we never want a state change without its audit entry.
 */
final class RecordSubscriptionStateChange
{
    public function handle(SubscriptionStateChanged $event): void
    {
        BillingAuditLog::create([
            'tenant_id' => $event->tenantId,
            'subscription_id' => $event->subscriptionId,
            'event_type' => 'subscription.state_changed',
            'payload' => [
                'from' => $event->from->value,
                'to' => $event->to->value,
            ],
            'correlation_id' => $event->correlationId,
            'occurred_at' => now(),
        ]);
    }
}
