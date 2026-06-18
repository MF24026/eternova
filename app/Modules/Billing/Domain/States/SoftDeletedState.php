<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/**
 * Grace ended. Billing data is preserved for tax/AML retention but the tenant has no
 * access. Hard-delete cron purges PII after the retention window; a rare manual
 * reactivation can bring it back to Active.
 */
final class SoftDeletedState extends SubscriptionState
{
    public function name(): SubscriptionStatus { return SubscriptionStatus::SoftDeleted; }

    public function canCharge(): bool { return false; }

    public function canCancel(): bool { return false; }

    public function canPause(): bool { return false; }

    public function canResume(): bool { return false; }

    public function canRefund(): bool { return false; }

    public function isAccessible(): bool { return false; }

    public function isReadOnly(): bool { return true; }

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Active,       // rare: tenant reactivates within retention
            SubscriptionStatus::HardDeleted,  // retention elapsed, PII purged
        ];
    }
}
