<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Services\TenantBillingService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/account/billing — the Owner's billing home: current subscription + plan + next
 * charge, plus the most recent invoices. Owner-only (billing.manage gate); scoped to the
 * resolved tenant by TenantBillingService.
 */
final class AccountBillingController extends Controller
{
    public function __construct(private readonly TenantBillingService $billing) {}

    public function __invoke(): JsonResponse
    {
        $this->authorize('billing.manage');

        $tenant = current_tenant();
        abort_if($tenant === null, 404);

        $subscription = $this->billing->current($tenant->id);

        return response()->json([
            'data' => [
                'subscription' => $subscription !== null ? new SubscriptionResource($subscription) : null,
                'recent_invoices' => InvoiceResource::collection($this->billing->invoices($tenant->id, 5)),
            ],
        ]);
    }
}
