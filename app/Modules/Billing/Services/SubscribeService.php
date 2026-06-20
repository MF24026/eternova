<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\RecurringPlanData;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use RuntimeException;

/**
 * Creates a Wompi-managed recurring payment link for a tenant and snapshots the
 * affiliation URL/QR onto the tenant's subscription. The gateway owns the charge
 * schedule (Wompi SV recurring model); we only store the link the tenant must affiliate.
 */
final class SubscribeService
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway,
        private readonly TenantBillingService $billing,
    ) {}

    public function subscribe(Tenant $tenant, Plan $plan): Subscription
    {
        $amountCents = (int) $plan->price_monthly_cents;

        $link = $this->gateway->createRecurringPaymentLink(new RecurringPlanData(
            amountCents: $amountCents,
            dayOfMonth: min((int) now()->day, 28),
            name: "{$plan->name} - {$tenant->name}",
            description: "Suscripcion {$plan->name} de Eternova",
        ));

        if (! $link->isSuccess()) {
            throw new RuntimeException('No se pudo crear el enlace de pago recurrente.');
        }

        $subscription = $this->billing->current($tenant->id)
            ?? new Subscription([
                'tenant_id' => $tenant->id,
                'status' => 'trialing',
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(30),
            ]);

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'amount_cents' => $amountCents,
            'currency' => (string) ($plan->currency ?? 'USD'),
            'gateway_subscription_id' => $link->linkId,
            'affiliation_url' => $link->shortUrl,
            'affiliation_qr_url' => $link->qrUrl,
        ])->save();

        return $subscription;
    }
}
