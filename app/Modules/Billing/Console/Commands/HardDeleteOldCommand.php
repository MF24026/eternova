<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Repositories\SubscriptionRepository;
use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;

/**
 * Final purge: soft-deleted subscriptions older than the retention window move to HardDeleted
 * and their PII (card token + display metadata, gateway customer id) is wiped. The anonymized
 * subscription row and the append-only audit log remain for tax/AML compliance.
 */
final class HardDeleteOldCommand extends Command
{
    protected $signature = 'billing:hard-delete-old';

    protected $description = 'Purge PII from soft-deleted subscriptions past the retention window.';

    public function handle(SubscriptionRepository $repository): int
    {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) config('billing.hard_delete_after_days', 180));
        $purged = 0;

        foreach ($repository->inState(SubscriptionStatus::SoftDeleted, $cutoff) as $subscription) {
            $state = $subscription->state();

            if (! $state->canTransitionTo(SubscriptionStatus::HardDeleted)) {
                continue;
            }

            $state->applyTransition(SubscriptionStatus::HardDeleted);

            $subscription->forceFill([
                'card_token' => null,
                'card_last4' => null,
                'card_brand' => null,
                'card_exp_month' => null,
                'card_exp_year' => null,
                'gateway_customer_id' => null,
            ])->save();

            $purged++;
        }

        $this->info("Hard-deleted (PII purged) {$purged} subscription(s).");

        return self::SUCCESS;
    }
}
