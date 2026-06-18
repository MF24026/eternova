<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Domain\Events\SubscriptionStateChanged;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\FakeGateway;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use App\Modules\Billing\Gateways\WompiGateway;
use App\Modules\Billing\Listeners\RecordSubscriptionStateChange;
use App\Modules\Billing\Support\CircuitBreaker;
use Illuminate\Support\Facades\Event;
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
                    privateKey: (string) config('billing.wompi.private_key'),
                    publicKey: (string) config('billing.wompi.public_key'),
                    eventsSecret: (string) config('billing.wompi.events_secret'),
                    baseUrl: (string) config('billing.wompi.base_url'),
                    errorTranslator: new WompiErrorTranslator(),
                    apiBreaker: new CircuitBreaker(
                        'gateway:wompi:api',
                        (int) config('billing.circuit.threshold', 5),
                        (int) config('billing.circuit.cooldown_seconds', 60),
                    ),
                    logger: Log::channel(),
                );
            }

            return new FakeGateway();
        });
    }

    public function boot(): void
    {
        Event::listen(SubscriptionStateChanged::class, RecordSubscriptionStateChange::class);
    }
}
