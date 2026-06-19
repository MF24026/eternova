<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Domain\Events\SubscriptionRenewed;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Notifications\InvoiceReadyNotification;
use App\Modules\Billing\Services\BillingNotificationService;
use App\Modules\Billing\Services\InvoicePdfService;
use App\Modules\Billing\Services\InvoiceService;

/**
 * On a successful renewal: issue the paid invoice, render its PDF, and email it to the tenant
 * owner. Kept synchronous because renewal volume is low (one daily cron pass); move the PDF +
 * notification to a queued job if volume ever warrants it.
 */
final class CreateInvoiceOnRenewal
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoicePdfService $pdf,
        private readonly BillingNotificationService $notifications,
    ) {}

    public function handle(SubscriptionRenewed $event): void
    {
        $subscription = Subscription::find($event->subscriptionId);

        if ($subscription === null) {
            return;
        }

        $invoice = $this->invoices->createPaidForRenewal($subscription);
        $this->pdf->generate($invoice);

        $this->notifications->notifyTenantOwner(
            $event->tenantId,
            new InvoiceReadyNotification($invoice),
        );
    }
}
