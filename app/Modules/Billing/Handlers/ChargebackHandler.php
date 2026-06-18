<?php

declare(strict_types=1);

namespace App\Modules\Billing\Handlers;

use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Records a chargeback for manual review. Deliberately does NOT auto-suspend the tenant: a
 * chargeback can be disputed and reversed, so suspension is an operator decision made from
 * the SuperAdmin console (Phase 7), not an automatic side-effect of the webhook.
 */
final class ChargebackHandler
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): void
    {
        $chargeback = $data['chargeback'] ?? $data;
        $reference = (string) ($chargeback['reference'] ?? ($data['transaction']['reference'] ?? ''));

        $subscription = Subscription::query()
            ->where('gateway_subscription_id', $reference)
            ->first();

        BillingAuditLog::create([
            'tenant_id' => $subscription?->tenant_id,
            'subscription_id' => $subscription?->id,
            'event_type' => 'chargeback.created',
            'payload' => [
                'chargeback_id' => $chargeback['id'] ?? null,
                'amount_cents' => $chargeback['amount_in_cents'] ?? null,
                'requires_manual_review' => true,
            ],
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }
}
