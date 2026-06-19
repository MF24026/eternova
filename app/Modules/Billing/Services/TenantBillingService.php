<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Support\Collection;

/**
 * Read helpers for the tenant-facing billing UI. Subscription/Invoice are NOT
 * tenant-scoped models (billing is cross-tenant by nature), so every query here filters by
 * tenant_id explicitly — this is the isolation boundary for the /account/billing endpoints.
 */
final class TenantBillingService
{
    /** Non-terminal states that represent the tenant's "current" subscription. */
    private const LIVE_STATES = ['trialing', 'active', 'past_due', 'paused', 'suspended'];

    public function current(string $tenantId): ?Subscription
    {
        return Subscription::query()
            ->with('plan')
            ->where('tenant_id', $tenantId)
            ->whereIn('status', self::LIVE_STATES)
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function invoices(string $tenantId, int $limit = 50): Collection
    {
        return Invoice::query()
            ->where('tenant_id', $tenantId)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Find an invoice that belongs to this tenant (returns null for a foreign/unknown id, so
     * the caller can 404 — never leak another tenant's invoice).
     */
    public function findInvoice(string $tenantId, int $invoiceId): ?Invoice
    {
        return Invoice::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($invoiceId)
            ->first();
    }
}
