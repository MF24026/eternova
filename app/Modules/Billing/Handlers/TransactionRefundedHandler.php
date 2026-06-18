<?php

declare(strict_types=1);

namespace App\Modules\Billing\Handlers;

use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Records a gateway-initiated refund in the append-only audit log. Invoice balance
 * adjustment + Owner notification arrive with the Phase 5 notification listeners; here we
 * just make sure the refund is durably and immutably recorded.
 */
final class TransactionRefundedHandler
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data): void
    {
        $tx = $data['transaction'] ?? $data;
        $reference = (string) ($tx['reference'] ?? '');

        $subscription = Subscription::query()
            ->where('gateway_subscription_id', $reference)
            ->first();

        BillingAuditLog::create([
            'tenant_id' => $subscription?->tenant_id,
            'subscription_id' => $subscription?->id,
            'event_type' => 'transaction.refunded',
            'payload' => [
                'transaction_id' => $tx['id'] ?? null,
                'amount_cents' => $tx['amount_in_cents'] ?? null,
            ],
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }
}
