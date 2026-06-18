<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/** Trial ended with no payment method. Read-only; can be revived by adding a card. */
final class ExpiredState extends SubscriptionState
{
    public function name(): SubscriptionStatus { return SubscriptionStatus::Expired; }

    public function canCharge(): bool { return true; }   // adding a card revives it

    public function canCancel(): bool { return false; }

    public function canPause(): bool { return false; }

    public function canResume(): bool { return false; }

    public function canRefund(): bool { return false; }

    public function isAccessible(): bool { return false; }

    public function isReadOnly(): bool { return true; }

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Active,       // tenant added a payment method late
            SubscriptionStatus::SoftDeleted,  // 30 days expired, swept away
        ];
    }
}
