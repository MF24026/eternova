<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/** Paid and current. The only fully-featured, writable state. */
final class ActiveState extends SubscriptionState
{
    public function name(): SubscriptionStatus { return SubscriptionStatus::Active; }

    public function canCharge(): bool { return true; }

    public function canCancel(): bool { return true; }

    public function canPause(): bool { return true; }

    public function canResume(): bool { return false; }

    public function canRefund(): bool { return true; }

    public function isAccessible(): bool { return true; }

    public function isReadOnly(): bool { return false; }

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::PastDue,   // recurring charge failed
            SubscriptionStatus::Paused,    // tenant paused
            SubscriptionStatus::Canceled,  // tenant cancelled
        ];
    }
}
