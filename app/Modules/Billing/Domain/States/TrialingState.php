<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/** Signup grace period. App is fully usable; first successful charge converts to Active. */
final class TrialingState extends SubscriptionState
{
    public function name(): SubscriptionStatus { return SubscriptionStatus::Trialing; }

    public function canCharge(): bool { return true; }

    public function canCancel(): bool { return true; }

    public function canPause(): bool { return false; }

    public function canResume(): bool { return false; }

    public function canRefund(): bool { return false; }

    public function isAccessible(): bool { return true; }

    public function isReadOnly(): bool { return false; }

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Active,    // first payment received
            SubscriptionStatus::Expired,   // trial ended without a payment method
            SubscriptionStatus::Canceled,  // tenant cancelled during trial
        ];
    }
}
