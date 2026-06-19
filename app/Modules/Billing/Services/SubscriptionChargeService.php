<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\ChargeResult;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Support\IdempotencyService;

/**
 * Builds a charge from a subscription's stored payment method and runs it through the gateway
 * behind the idempotency ledger, so the same logical charge (same key) never hits the gateway
 * twice. The caller supplies the key (per period for renewals, per attempt for dunning).
 */
final readonly class SubscriptionChargeService
{
    public function __construct(
        private PaymentGatewayInterface $gateway,
        private IdempotencyService $idempotency,
    ) {}

    public function charge(Subscription $subscription, string $idempotencyKey): ChargeResult
    {
        return $this->idempotency->execute(
            $idempotencyKey,
            'charge',
            (string) $subscription->tenant_id,
            fn (): ChargeResult => $this->gateway->charge($this->buildChargeData($subscription)),
        );
    }

    private function buildChargeData(Subscription $subscription): ChargeData
    {
        $tenant = $subscription->tenant;

        return new ChargeData(
            amountCents: (int) ($subscription->amount_cents ?? 0),
            currency: (string) ($subscription->currency ?? 'USD'),
            customerEmail: (string) ($tenant?->email ?? ''),
            cardToken: (string) ($subscription->card_token ?? ''),
            reference: (string) ($subscription->gateway_subscription_id ?? "sub_{$subscription->id}"),
            tenantId: (string) $subscription->tenant_id,
            // Wompi SV requires nombreCliente; use the tenant's business/legal name.
            customerName: $tenant?->business_name ?? $tenant?->name,
        );
    }
}
