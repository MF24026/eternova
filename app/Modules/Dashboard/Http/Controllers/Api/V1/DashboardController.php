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
 * Visible to any authenticated tenant member (the underlying data — orders,
 * expenses, inventory — is already staff-visible). The 'tenant' middleware
 * guarantees a resolved tenant; DashboardService scopes every figure to it.
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
        $range = (int) $request->query('range', '14');

        if (! in_array($range, self::ALLOWED_RANGES, true)) {
            $range = 14;
        }

        return response()->json([
            'data' => $this->dashboard->summary($range),
        ]);
    }
}
