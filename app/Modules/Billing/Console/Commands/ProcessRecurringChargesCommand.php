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
 * Charges every active subscription whose next billing date has arrived. A success rolls the
 * period forward; a decline drops the subscription into dunning. Transient errors (network,
 * open circuit, in-flight idempotent op) are left untouched for the next run.
 *
 * Idempotent: the charge is keyed per billing period, so a double run never double-charges.
 */
final class ProcessRecurringChargesCommand extends Command
{
    protected $signature = 'billing:process-recurring-charges';

    protected $description = 'Charge active subscriptions whose billing date is due.';

    public function handle(
        SubscriptionRepository $repository,
        SubscriptionChargeService $chargeService,
        DunningService $dunning,
    ): int {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $due = $repository->dueForRecurringCharge(now());

        foreach ($due as $subscription) {
            $key = "recurring:{$subscription->id}:".(int) $subscription->next_billing_at?->getTimestamp();

            try {
                $result = $chargeService->charge($subscription, $key);
            } catch (Throwable $e) {
                // Transient — leave the subscription as-is and try again next run.
                $this->warn("Skipped subscription {$subscription->id}: {$e->getMessage()}");

                continue;
            }

            $result->isSuccess()
                ? $dunning->applyRenewal($subscription)
                : $dunning->applyChargeFailure($subscription);
        }

        $this->info("Processed {$due->count()} due subscription(s).");

        return self::SUCCESS;
    }
}
