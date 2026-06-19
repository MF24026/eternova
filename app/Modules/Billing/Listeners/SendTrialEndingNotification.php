<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Domain\Events\TrialEndingSoon;
use App\Modules\Billing\Notifications\TrialEndingNotification;
use App\Modules\Billing\Services\BillingNotificationService;

final class SendTrialEndingNotification
{
    public function __construct(private readonly BillingNotificationService $notifications) {}

    public function handle(TrialEndingSoon $event): void
    {
        $this->notifications->notifyTenantOwner(
            $event->tenantId,
            new TrialEndingNotification($event->daysLeft),
        );
    }
}
