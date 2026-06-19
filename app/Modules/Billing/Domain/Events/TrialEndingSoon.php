<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A trial is ending within the reminder window and the tenant has not been warned yet. */
final class TrialEndingSoon
{
    use Dispatchable;

    public function __construct(
        public readonly int $subscriptionId,
        public readonly string $tenantId,
        public readonly int $daysLeft,
    ) {}
}
