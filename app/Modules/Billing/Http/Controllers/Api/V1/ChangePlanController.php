<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\ChangePlanRequest;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Services\TenantBillingService;
use App\Modules\Plans\Models\Plan;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/account/billing/plan — switch the subscription's plan. The new price takes
 * effect from the next recurring charge (the new amount is snapshotted onto the subscription).
 * Owner-only. Proration of the in-flight period is deferred — the upgrade-with-immediate-charge
 * path lands with the Phase 6 UI once the Wompi public key is available for the iframe.
 */
final class ChangePlanController extends Controller
{
    public function __construct(private readonly TenantBillingService $billing) {}

    public function __invoke(ChangePlanRequest $request): JsonResponse
    {
        $tenant = current_tenant();
        abort_if($tenant === null, 404);

        $subscription = $this->billing->current($tenant->id);
        abort_if($subscription === null, 404, 'No active subscription to change.');

        $plan = Plan::findOrFail($request->integer('plan_id'));

        $amount = $subscription->billing_period === 'yearly'
            ? $plan->price_yearly_cents
            : $plan->price_monthly_cents;

        $subscription->forceFill([
            'plan_id' => $plan->id,
            'amount_cents' => $amount,
        ])->save();

        return response()->json(['data' => new SubscriptionResource($subscription->fresh()->load('plan'))]);
    }
}
