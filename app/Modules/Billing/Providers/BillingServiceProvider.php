<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Domain\Events\SubscriptionStateChanged;
use App\Modules\Billing\Listeners\RecordSubscriptionStateChange;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the Billing module. Phase 1 registers the audit-log listener that turns every
 * subscription state change into an append-only billing_audit_log row. Later phases add
 * the gateway binding, webhook routes, cron schedule and notification listeners here.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(SubscriptionStateChanged::class, RecordSubscriptionStateChange::class);
    }
}
