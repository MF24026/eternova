<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/** Last charge failed; in dunning. Still accessible during the retry window. */
final class PastDueState extends SubscriptionState
{
    public function name(): SubscriptionStatus
    {
        return SubscriptionStatus::PastDue;
    }

    public function canCharge(): bool
    {
        return true;
    }   // dunning retries charge here

    public function canCancel(): bool
    {
        return true;
    }

    public function canPause(): bool
    {
        return false;
    }

    public function canResume(): bool
    {
        return false;
    }

    public function canRefund(): bool
    {
        return false;
    }

    public function isAccessible(): bool
    {
        return true;
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Active,     // a retry succeeded
            SubscriptionStatus::Suspended,  // all retries exhausted
            SubscriptionStatus::Canceled,   // tenant cancelled while past due
        ];
    }
}
