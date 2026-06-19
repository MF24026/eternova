<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Repositories\SubscriptionRepository;
use App\Modules\Billing\Services\DunningService;
use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;

/**
 * Suspends past-due subscriptions that have exhausted their retries or have been past due
 * longer than the dunning window. Suspension makes the account read-only until a back-payment.
 */
final class SuspendOverdueCommand extends Command
{
    protected $signature = 'billing:suspend-overdue';

    protected $description = 'Suspend past-due subscriptions whose dunning is exhausted.';

    public function handle(SubscriptionRepository $repository, DunningService $dunning): int
    {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $maxRetries = $dunning->maxRetries();
        /** @var list<int> $days */
        $days = config('billing.dunning_retry_days', [3, 7, 14]);
        $overdueCutoff = now()->subDays((int) (max($days) ?: 14));

        $suspended = 0;

        foreach ($repository->inState(SubscriptionStatus::PastDue) as $subscription) {
            $exhausted = (int) $subscription->retry_count >= $maxRetries;
            $tooOld = $subscription->past_due_since !== null
                && $subscription->past_due_since->lt($overdueCutoff);

            if ($exhausted || $tooOld) {
                $dunning->suspend($subscription);
                $suspended++;
            }
        }

        $this->info("Suspended {$suspended} subscription(s).");

        return self::SUCCESS;
    }
}
