<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Dunning was exhausted and the subscription was suspended (account read-only). */
final class SubscriptionSuspended
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $tenantId,
    ) {}
}
