<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\SuperAdmin\Http\Requests\ExtendTrialRequest;
use App\Modules\SuperAdmin\Http\Requests\OperatorReasonRequest;
use App\Modules\SuperAdmin\Services\SuperAdminBillingActionService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Manual operator actions on a tenant's subscription. Each requires a reason (validated in the
 * Form Request) and is audited by the service. Super-admin only.
 */
final class SuperAdminBillingActionController extends Controller
{
    public function __construct(private readonly SuperAdminBillingActionService $actions) {}

    public function extendTrial(ExtendTrialRequest $request, Tenant $tenant): JsonResponse
    {
        $subscription = $this->actions->extendTrial(
            $tenant,
            $request->integer('days'),
            $request->user(),
            (string) $request->string('reason'),
        );

        return response()->json(['data' => new SubscriptionResource($subscription->load('plan'))]);
    }

    public function suspend(OperatorReasonRequest $request, Tenant $tenant): JsonResponse
    {
        $subscription = $this->actions->suspend($tenant, $request->user(), (string) $request->string('reason'));

        return response()->json(['data' => new SubscriptionResource($subscription->load('plan'))]);
    }

    public function reactivate(OperatorReasonRequest $request, Tenant $tenant): JsonResponse
    {
        $subscription = $this->actions->reactivate($tenant, $request->user(), (string) $request->string('reason'));

        return response()->json(['data' => new SubscriptionResource($subscription->load('plan'))]);
    }
}
