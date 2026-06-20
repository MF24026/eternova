<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Billing\Services\TenantBillingService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/account/billing/cancel — cancel at period end (the tenant keeps access until
 * current_period_end). Owner-only. Immediate/refund cancellation is an operator action in the
 * Phase 7 SuperAdmin console, not a self-serve tenant action.
 */
final class CancelSubscriptionController extends Controller
{
    public function __construct(
        private readonly TenantBillingService $billing,
        private readonly SubscriptionService $subscriptions,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    public function __invoke(): JsonResponse
    {
        $this->authorize('billing.manage');

        $tenant = current_tenant();
        abort_if($tenant === null, 404);

        $subscription = $this->billing->current($tenant->id);
        abort_if($subscription === null, 404, 'No active subscription to cancel.');

        $this->subscriptions->cancel($subscription, atPeriodEnd: true);

        if ($subscription->gateway_subscription_id !== null) {
            // Best-effort: never block the local cancel on a gateway failure.
            $this->gateway->cancelRecurringPaymentLink((string) $subscription->gateway_subscription_id);
        }

        return response()->json(['data' => new SubscriptionResource($subscription->fresh()->load('plan'))]);
    }
}
