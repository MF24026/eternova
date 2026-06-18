<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Enums\SubscriptionStatus;

/**
 * Tenant cancelled. Access continues until current_period_end (grace) — the
 * Subscription::isOnGracePeriod() helper governs that window; the soft-delete cron moves
 * the row to SoftDeleted once the period lapses. A refund of the last charge is still
 * possible, and a tenant may re-subscribe (back to Active) during grace.
 */
final class CanceledState extends SubscriptionState
{
    public function name(): SubscriptionStatus { return SubscriptionStatus::Canceled; }

    public function canCharge(): bool { return false; }

    public function canCancel(): bool { return false; }

    public function canPause(): bool { return false; }

    public function canResume(): bool { return false; }

    public function canRefund(): bool { return true; }

    public function isAccessible(): bool { return true; }   // until grace ends

    public function isReadOnly(): bool { return false; }

    public function allowedTransitions(): array
    {
        return [
            SubscriptionStatus::Active,       // tenant re-subscribed during grace
            SubscriptionStatus::SoftDeleted,  // billing period ended
        ];
    }
}
