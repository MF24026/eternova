<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A charge failed and the subscription entered (or stayed in) dunning. */
final class SubscriptionChargeFailed
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $tenantId,
    ) {}
}
