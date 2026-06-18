<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Events;

use App\Modules\Billing\Enums\SubscriptionStatus;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by SubscriptionState::applyTransition() on every successful state change.
 *
 * The audit-log listener turns this into an append-only billing_audit_log row; later
 * phases add notification listeners. The correlation_id threads a single business action
 * (a webhook, a cron pass, an operator click) across every event it spawns.
 */
final class SubscriptionStateChanged
{
    use Dispatchable;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $subscriptionId,
        public readonly SubscriptionStatus $from,
        public readonly SubscriptionStatus $to,
        public readonly string $correlationId,
    ) {}
}
