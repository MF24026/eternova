<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Repositories\SubscriptionRepository;
use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;

/**
 * Moves end-of-life subscriptions to SoftDeleted, where billing data is retained but the
 * tenant has no access:
 *   - canceled subscriptions whose paid period has lapsed;
 *   - expired and suspended subscriptions past the grace window.
 *
 * SoftDeleted rows are later PII-purged by billing:hard-delete-old.
 */
final class SoftDeleteCancelledCommand extends Command
{
    protected $signature = 'billing:soft-delete-cancelled';

    protected $description = 'Soft-delete canceled/expired/suspended subscriptions past their grace.';

    public function handle(SubscriptionRepository $repository): int
    {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $graceCutoff = now()->subDays((int) config('billing.grace_after_suspend_days', 30));
        $count = 0;

        // Canceled subscriptions whose paid period has ended.
        foreach ($repository->inState(SubscriptionStatus::Canceled) as $subscription) {
            if ($subscription->current_period_end !== null && $subscription->current_period_end->isPast()) {
                $count += $this->softDelete($subscription);
            }
        }

        // Expired / suspended subscriptions that have sat past the grace window.
        foreach ([SubscriptionStatus::Expired, SubscriptionStatus::Suspended] as $status) {
            foreach ($repository->inState($status, $graceCutoff) as $subscription) {
                $count += $this->softDelete($subscription);
            }
        }

        $this->info("Soft-deleted {$count} subscription(s).");

        return self::SUCCESS;
    }

    private function softDelete(Subscription $subscription): int
    {
        $state = $subscription->state();

        if (! $state->canTransitionTo(SubscriptionStatus::SoftDeleted)) {
            return 0;
        }

        $state->applyTransition(SubscriptionStatus::SoftDeleted);

        return 1;
    }
}
