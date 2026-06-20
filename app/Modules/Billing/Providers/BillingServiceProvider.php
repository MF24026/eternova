<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Console\Commands\BillingMaintenanceCommand;
use App\Modules\Billing\Console\Commands\HardDeleteOldCommand;
use App\Modules\Billing\Console\Commands\ProcessRecurringChargesCommand;
use App\Modules\Billing\Console\Commands\ReconcileSubscriptionsCommand;
use App\Modules\Billing\Console\Commands\RetryDunningCommand;
use App\Modules\Billing\Console\Commands\SendTrialRemindersCommand;
use App\Modules\Billing\Console\Commands\SoftDeleteCancelledCommand;
use App\Modules\Billing\Console\Commands\SuspendOverdueCommand;
use App\Modules\Billing\Domain\Events\SubscriptionChargeFailed;
use App\Modules\Billing\Domain\Events\SubscriptionRenewed;
use App\Modules\Billing\Domain\Events\SubscriptionStateChanged;
use App\Modules\Billing\Domain\Events\SubscriptionSuspended;
use App\Modules\Billing\Domain\Events\TrialEndingSoon;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\FakeGateway;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use App\Modules\Billing\Gateways\WompiGateway;
use App\Modules\Billing\Listeners\CreateInvoiceOnRenewal;
use App\Modules\Billing\Listeners\RecordSubscriptionStateChange;
use App\Modules\Billing\Listeners\SendChargeFailedNotification;
use App\Modules\Billing\Listeners\SendSuspendedNotification;
use App\Modules\Billing\Listeners\SendTrialEndingNotification;
use App\Modules\Billing\Support\CircuitBreaker;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Billing module:
 *   - the audit-log listener (Phase 1) that records every subscription state change;
 *   - the PaymentGatewayInterface binding (Phase 2), driver-selected via config('billing').
 *     Defaults to FakeGateway so the stack runs without real credentials; switch to Wompi
 *     by setting BILLING_DRIVER=wompi plus the WOMPI_* keys.
 *
 * Later phases add webhook routes, the cron schedule and notification listeners here.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayInterface::class, function (): PaymentGatewayInterface {
            if (config('billing.driver') === 'wompi') {
                return new WompiGateway(
                    appId: (string) config('billing.wompi.app_id'),
                    apiSecret: (string) config('billing.wompi.api_secret'),
                    authBaseUrl: (string) config('billing.wompi.auth_url'),
                    baseUrl: (string) config('billing.wompi.base_url'),
                    errorTranslator: new WompiErrorTranslator(),
                    apiBreaker: new CircuitBreaker(
                        'gateway:wompi:api',
                        (int) config('billing.circuit.threshold', 5),
                        (int) config('billing.circuit.cooldown_seconds', 60),
                    ),
                    logger: Log::channel('billing'),
                );
            }

            return new FakeGateway();
        });
    }

    public function boot(): void
    {
        // Billing is Owner-only (the skill's hard rule): admin/staff/customer never see it.
        // super_admin is allowed for support/impersonation. Branch axis does not exist here.
        Gate::define('billing.manage', static function (User $user): bool {
            return $user->is_super_admin || $user->currentRole() === 'owner';
        });

        Event::listen(SubscriptionStateChanged::class, RecordSubscriptionStateChange::class);

        // Phase 5 — domain events -> notifications (+ invoice issuance on renewal).
        Event::listen(TrialEndingSoon::class, SendTrialEndingNotification::class);
        Event::listen(SubscriptionChargeFailed::class, SendChargeFailedNotification::class);
        Event::listen(SubscriptionSuspended::class, SendSuspendedNotification::class);
        Event::listen(SubscriptionRenewed::class, CreateInvoiceOnRenewal::class);

        if ($this->app->runningInConsole()) {
            // Module commands live outside app/Console/Commands, so register them explicitly.
            $this->commands([
                BillingMaintenanceCommand::class,
                ProcessRecurringChargesCommand::class,
                RetryDunningCommand::class,
                SuspendOverdueCommand::class,
                SoftDeleteCancelledCommand::class,
                HardDeleteOldCommand::class,
                ReconcileSubscriptionsCommand::class,
                SendTrialRemindersCommand::class,
            ]);
        }
    }
}
