<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A recurring (or first) charge succeeded and the subscription period was extended. */
final class SubscriptionRenewed
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $tenantId,
    ) {}
}
