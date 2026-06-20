<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Requests\SubscribeRequest;
use App\Modules\Billing\Services\SubscribeService;
use App\Modules\Plans\Models\Plan;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/account/billing/subscribe — create a Wompi recurring payment link for the
 * tenant on the chosen plan and return the affiliation URL the owner must visit to affiliate
 * their card. Owner-only (billing.manage gate via SubscribeRequest).
 */
final class SubscribeController extends Controller
{
    public function __construct(private readonly SubscribeService $subscribe) {}

    public function __invoke(SubscribeRequest $request): JsonResponse
    {
        $tenant = current_tenant();
        abort_if($tenant === null, 404);

        $plan = Plan::findOrFail($request->integer('plan_id'));

        $subscription = $this->subscribe->subscribe($tenant, $plan);

        return response()->json(['data' => [
            'affiliation_url' => $subscription->affiliation_url,
            'affiliation_qr_url' => $subscription->affiliation_qr_url,
            'status' => $subscription->status,
        ]]);
    }
}
