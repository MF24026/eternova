<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/** Dunning exhausted. Account is read-only; a back-payment restores Active. */
final class SuspendedState extends SubscriptionState
{
    public function name(): SubscriptionStatus
    {
        return SubscriptionStatus::Suspended;
    }

    public function canCharge(): bool
    {
        return true;
    }   // back-payment to restore

    public function canCancel(): bool
    {
        return false;
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
    }   // visible but...

    public function isReadOnly(): bool
    {
        return true;
    }     // ...read-only

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Active,       // back-payment received
            SubscriptionStatus::SoftDeleted,  // 30 days suspended, swept away
        ];
    }
}
