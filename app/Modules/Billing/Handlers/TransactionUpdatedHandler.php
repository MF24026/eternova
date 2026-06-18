<?php

declare(strict_types=1);

namespace App\Modules\Billing\Handlers;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;

/**
 * Maps a Wompi transaction.updated event onto a subscription transition. The subscription is
 * located by gateway_subscription_id == the transaction reference. Every status change goes
 * through the state machine so it is validated + audited; a status the machine cannot apply
 * from the current state is left alone (idempotent — replays don't double-advance).
 */
final class TransactionUpdatedHandler
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): void
    {
        $tx = $data['transaction'] ?? $data;
        $reference = (string) ($tx['reference'] ?? '');

        $subscription = Subscription::query()
            ->where('gateway_subscription_id', $reference)
            ->first();

        if ($subscription === null) {
            return;
        }

        match ((string) ($tx['status'] ?? '')) {
            'APPROVED' => $this->markRenewed($subscription),
            'DECLINED', 'ERROR' => $this->markChargeFailed($subscription),
            'VOIDED' => $this->markVoided($subscription),
            default => null,
        };
    }

    private function markRenewed(Subscription $subscription): void
    {
        $state = $subscription->state();

        if ($state->name() !== SubscriptionStatus::Active && $state->canTransitionTo(SubscriptionStatus::Active)) {
            $state->applyTransition(SubscriptionStatus::Active);
        }

        $subscription->forceFill([
            'next_billing_at' => now()->addMonth(),
            'last_paid_at' => now(),
            'retry_count' => 0,
            'next_retry_at' => null,
            'past_due_since' => null,
        ])->save();
    }

    private function markChargeFailed(Subscription $subscription): void
    {
        $state = $subscription->state();

        if (! $state->canTransitionTo(SubscriptionStatus::PastDue)) {
            return;
        }

        $state->applyTransition(SubscriptionStatus::PastDue);

        $firstRetryDays = config('billing.dunning_retry_days')[0] ?? 3;

        $subscription->forceFill([
            'past_due_since' => now(),
            'retry_count' => 0,
            'next_retry_at' => now()->addDays((int) $firstRetryDays),
        ])->save();
    }

    private function markVoided(Subscription $subscription): void
    {
        $state = $subscription->state();

        if (! $state->canTransitionTo(SubscriptionStatus::Canceled)) {
            return;
        }

        $state->applyTransition(SubscriptionStatus::Canceled);
        $subscription->forceFill(['canceled_at' => now()])->save();
    }
}
