<?php

declare(strict_types=1);

namespace App\Modules\Dashboard\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Dashboard\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant dashboard summary.
 *
 *   GET /api/v1/dashboard?range=14   → KPIs + sales series + top products + recent orders
 *
 * Gated by the 'dashboard.view' ability (owner/admin/staff of the resolved
 * tenant, or super-admin). This is what stops an authenticated user from reading
 * a tenant they do not belong to, and excludes the read-only 'customer' role —
 * the figures include expenses and customer names. DashboardService then scopes
 * every figure to the current tenant.
 */
final class DashboardController extends Controller
{
    /** Allowed chart ranges in days. */
    private const ALLOWED_RANGES = [7, 14, 30];

    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->authorize('dashboard.view');

        $range = (int) $request->query('range', '14');

        if (! in_array($range, self::ALLOWED_RANGES, true)) {
            $range = 14;
        }

        return response()->json([
            'data' => $this->dashboard->summary($range),
        ]);
    }
}
