<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\SuperAdmin\Services\BillingMetricsService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v1/super-admin/billing/metrics — MRR, ARPU, churn, plan distribution, state counts.
 * Super-admin only (the route's super_admin middleware is the gate).
 */
final class SuperAdminBillingMetricsController extends Controller
{
    public function __construct(private readonly BillingMetricsService $metrics) {}

    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => $this->metrics->summary()]);
    }
}
