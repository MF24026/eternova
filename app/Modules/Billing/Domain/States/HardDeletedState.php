<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/** Terminal. PII purged; only the anonymized audit trail remains. No transitions out. */
final class HardDeletedState extends SubscriptionState
{
    public function name(): SubscriptionStatus
    {
        return SubscriptionStatus::HardDeleted;
    }

    public function canCharge(): bool
    {
        return false;
    }

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
        return false;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function allowedTransitions(): array
    {
        return [];
    }
}
