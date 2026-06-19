<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Models\ReconciliationDiscrepancy;
use App\Modules\Billing\Repositories\SubscriptionRepository;
use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;

/**
 * Compares each active subscription against what the gateway reports for its last transaction.
 * A mismatch (we think active, the gateway says voided/declined/refunded) is flagged in
 * reconciliation_discrepancies for manual operator review — never auto-corrected, because
 * money drift always gets a human.
 */
final class ReconcileSubscriptionsCommand extends Command
{
    protected $signature = 'billing:reconcile-subscriptions';

    protected $description = 'Flag subscriptions whose state has drifted from the gateway.';

    public function handle(SubscriptionRepository $repository, PaymentGatewayInterface $gateway): int
    {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $flagged = 0;

        foreach ($repository->inState(SubscriptionStatus::Active) as $subscription) {
            if ($subscription->gateway_subscription_id === null) {
                continue;
            }

            $transaction = $gateway->getTransaction((string) $subscription->gateway_subscription_id);

            if ($transaction === null || $transaction->status === 'APPROVED') {
                continue;
            }

            ReconciliationDiscrepancy::create([
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'our_state' => ['status' => $subscription->status],
                'gateway_state' => ['status' => $transaction->status],
                'diff_summary' => "We have 'active' but gateway reports '{$transaction->status}'.",
                'resolved' => false,
                'detected_at' => now(),
            ]);

            $flagged++;
        }

        $this->info("Flagged {$flagged} discrepancy(ies).");

        return self::SUCCESS;
    }
}
