<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Domain\Events\SubscriptionChargeFailed;
use App\Modules\Billing\Domain\Events\SubscriptionRenewed;
use App\Modules\Billing\Domain\Events\SubscriptionSuspended;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;

/**
 * Owns the dunning state transitions: what happens to a subscription when a charge succeeds,
 * fails, or when retries run out. All status changes go through the state machine (validated
 * + audited); every method is idempotent so a re-run of a cron does not double-advance.
 */
final class DunningService
{
    /**
     * A charge succeeded: (re)activate and roll the billing period forward, clearing all
     * dunning bookkeeping.
     */
    public function applyRenewal(Subscription $subscription): void
    {
        $state = $subscription->state();

        if ($state->name() !== SubscriptionStatus::Active && $state->canTransitionTo(SubscriptionStatus::Active)) {
            $state->applyTransition(SubscriptionStatus::Active);
        }

        $periodEnd = ($subscription->billing_period === 'yearly') ? now()->addYear() : now()->addMonth();

        $subscription->forceFill([
            'current_period_start' => now(),
            'current_period_end' => $periodEnd,
            'next_billing_at' => $periodEnd,
            'last_paid_at' => now(),
            'retry_count' => 0,
            'next_retry_at' => null,
            'past_due_since' => null,
        ])->save();

        SubscriptionRenewed::dispatch($subscription->id, (string) $subscription->tenant_id);
    }

    /**
     * A charge failed: enter dunning (active -> past_due) and schedule the first retry. A
     * subscription already past_due just gets its next retry rescheduled.
     */
    public function applyChargeFailure(Subscription $subscription): void
    {
        $state = $subscription->state();

        if ($state->canTransitionTo(SubscriptionStatus::PastDue)) {
            $state->applyTransition(SubscriptionStatus::PastDue);
            $subscription->forceFill(['past_due_since' => $subscription->past_due_since ?? now()])->save();
        }

        $this->scheduleNextRetry($subscription);

        SubscriptionChargeFailed::dispatch($subscription->id, (string) $subscription->tenant_id);
    }

    /**
     * Advance the retry counter and set the next retry date from the configured schedule
     * (e.g. day 3, 7, 14). Clamps to the last interval once the schedule is exhausted.
     */
    public function scheduleNextRetry(Subscription $subscription): void
    {
        /** @var list<int> $days */
        $days = config('billing.dunning_retry_days', [3, 7, 14]);
        $index = min((int) $subscription->retry_count, count($days) - 1);

        $subscription->forceFill([
            'retry_count' => (int) $subscription->retry_count + 1,
            'next_retry_at' => now()->addDays((int) $days[$index]),
        ])->save();
    }

    /**
     * Dunning exhausted: suspend the subscription (account becomes read-only).
     */
    public function suspend(Subscription $subscription): void
    {
        $state = $subscription->state();

        if (! $state->canTransitionTo(SubscriptionStatus::Suspended)) {
            return;
        }

        $state->applyTransition(SubscriptionStatus::Suspended);

        SubscriptionSuspended::dispatch($subscription->id, (string) $subscription->tenant_id);
    }

    public function maxRetries(): int
    {
        return count((array) config('billing.dunning_retry_days', [3, 7, 14]));
    }
}
