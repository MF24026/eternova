<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/** Tenant-requested pause. No billing, no access until resumed. */
final class PausedState extends SubscriptionState
{
    public function name(): SubscriptionStatus { return SubscriptionStatus::Paused; }

    public function canCharge(): bool { return false; }

    public function canCancel(): bool { return true; }

    public function canPause(): bool { return false; }

    public function canResume(): bool { return true; }

    public function canRefund(): bool { return false; }

    public function isAccessible(): bool { return false; }

    public function isReadOnly(): bool { return true; }

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Active,    // tenant resumed
            SubscriptionStatus::Canceled,  // tenant cancelled while paused
        ];
    }
}
