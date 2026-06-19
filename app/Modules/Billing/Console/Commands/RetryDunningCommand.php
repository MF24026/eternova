<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Repositories\SubscriptionRepository;
use App\Modules\Billing\Services\DunningService;
use App\Modules\Billing\Services\SubscriptionChargeService;
use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;
use Throwable;

/**
 * Retries the charge for past-due subscriptions whose next retry is due and which still have
 * attempts left. Success recovers the subscription; failure reschedules the next attempt on
 * the configured ladder (day 3, 7, 14). When attempts run out, the suspend cron takes over.
 */
final class RetryDunningCommand extends Command
{
    protected $signature = 'billing:retry-dunning';

    protected $description = 'Retry failed charges for subscriptions in dunning.';

    public function handle(
        SubscriptionRepository $repository,
        SubscriptionChargeService $chargeService,
        DunningService $dunning,
    ): int {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $due = $repository->dueForDunningRetry(now(), $dunning->maxRetries());

        foreach ($due as $subscription) {
            $key = "dunning:{$subscription->id}:{$subscription->retry_count}";

            try {
                $result = $chargeService->charge($subscription, $key);
            } catch (Throwable $e) {
                $this->warn("Skipped subscription {$subscription->id}: {$e->getMessage()}");

                continue;
            }

            $result->isSuccess()
                ? $dunning->applyRenewal($subscription)
                : $dunning->scheduleNextRetry($subscription);
        }

        $this->info("Retried {$due->count()} subscription(s).");

        return self::SUCCESS;
    }
}
