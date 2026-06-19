<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;

/**
 * Creates invoices for billing events. For now the only issuer is a successful renewal, which
 * produces a paid invoice for the subscription's amount. Tax is 0 until per-tenant tax rates
 * land; the totals-consistency invariant (subtotal + tax == total) is always preserved.
 */
final class InvoiceService
{
    public function createPaidForRenewal(Subscription $subscription): Invoice
    {
        $amount = (int) ($subscription->amount_cents ?? 0);

        return Invoice::create([
            'tenant_id' => $subscription->tenant_id,
            'subscription_id' => $subscription->id,
            'number' => $this->nextNumber($subscription),
            'status' => 'paid',
            'subtotal_cents' => $amount,
            'tax_cents' => 0,
            'total_cents' => $amount,
            'currency' => (string) ($subscription->currency ?? 'USD'),
            'due_at' => null,
            'paid_at' => now(),
        ]);
    }

    /**
     * Invoice number format: INV-YYYYMMDD-{tenant-suffix}-{sequential}. The number column is
     * UNIQUE; the per-tenant sequence is derived from the existing count (invoices are issued
     * by the single-threaded renewal cron, so the count is a safe sequence source).
     */
    private function nextNumber(Subscription $subscription): string
    {
        $sequence = Invoice::query()->where('tenant_id', $subscription->tenant_id)->count() + 1;
        $tenantSuffix = strtoupper(substr((string) $subscription->tenant_id, -6));

        return sprintf('INV-%s-%s-%04d', now()->format('Ymd'), $tenantSuffix, $sequence);
    }
}
