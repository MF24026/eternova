<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Domain\Events\TrialEndingSoon;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;

/**
 * Warns tenants whose trial ends within the reminder window. Idempotent via
 * trial_reminder_sent_at, so a daily run never re-spams a tenant. The actual notification is
 * sent by the Phase 5 listener on the TrialEndingSoon event dispatched here.
 */
final class SendTrialRemindersCommand extends Command
{
    protected $signature = 'billing:send-trial-reminders {--days=3 : Remind when the trial ends within this many days}';

    protected $description = 'Dispatch trial-ending reminders for trials about to lapse.';

    public function handle(): int
    {
        if (BillingMaintenance::isEnabled()) {
            $this->warn('Billing maintenance is enabled; skipping.');

            return self::SUCCESS;
        }

        $window = (int) $this->option('days');

        $subscriptions = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNull('trial_reminder_sent_at')
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now())
            ->where('trial_ends_at', '<=', now()->addDays($window))
            ->get();

        foreach ($subscriptions as $subscription) {
            $daysLeft = (int) ceil(now()->diffInDays($subscription->trial_ends_at, absolute: false));

            TrialEndingSoon::dispatch($subscription->id, (string) $subscription->tenant_id, max(0, $daysLeft));

            $subscription->forceFill(['trial_reminder_sent_at' => now()])->save();
        }

        $this->info("Sent {$subscriptions->count()} trial reminder(s).");

        return self::SUCCESS;
    }
}
