<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Repositories\SubscriptionRepository;
use App\Modules\Billing\Services\DunningService;
use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;

/**
 * Suspends past-due subscriptions that have been past due longer than the dunning window
 * (Wompi owns recurrence + retries now). Suspension makes the account read-only until a
 * back-payment.
 */
final class SuspendOverdueCommand extends Command
{
    protected $signature = 'billing:suspend-overdue';

    protected $description = 'Suspend past-due subscriptions older than the dunning window.';

    public function handle(SubscriptionRepository $repository, DunningService $dunning): int
    {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $overdueCutoff = now()->subDays((int) (max((array) config('billing.dunning_retry_days', [3, 7, 14])) ?: 14));
        $suspended = 0;
        foreach ($repository->inState(SubscriptionStatus::PastDue) as $subscription) {
            if ($subscription->past_due_since !== null && $subscription->past_due_since->lt($overdueCutoff)) {
                $dunning->suspend($subscription);
                $suspended++;
            }
        }
        $this->info("Suspended {$suspended} subscription(s).");

        return self::SUCCESS;
    }
}
