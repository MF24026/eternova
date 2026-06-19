<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Domain\Events\SubscriptionSuspended;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Notifications\SubscriptionSuspendedNotification;
use App\Modules\Billing\Services\BillingNotificationService;

final class SendSuspendedNotification
{
    public function __construct(private readonly BillingNotificationService $notifications) {}

    public function handle(SubscriptionSuspended $event): void
    {
        $subscription = Subscription::find($event->subscriptionId);

        if ($subscription === null) {
            return;
        }

        $this->notifications->notifyTenantOwner(
            $event->tenantId,
            new SubscriptionSuspendedNotification($subscription),
        );
    }
}
