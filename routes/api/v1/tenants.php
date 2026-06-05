<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Api\V1\TenantProvisionController;
use App\Modules\Plans\Http\Resources\PlanResource;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\SlugValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenants API Routes — /api/v1/tenants/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
|
| GET  /api/v1/tenants/check-slug — public slug availability check (throttled)
| GET  /api/v1/plans              — public active plans listing for the wizard
| POST /api/v1/tenants            — provision a new tenant (auth required)
*/

// Public: check whether a tenant slug is available.
// Rate-limited to 60 req/min/IP so it cannot be used as a slug enumeration oracle.
Route::middleware('throttle:60,1')->get('/tenants/check-slug', static function (Request $request): JsonResponse {
    $slug = (string) $request->query('slug', '');

    $requestId = (string) $request->header('X-Request-Id', '');
    $meta = ['request_id' => $requestId];

    if (! SlugValidator::isValid($slug)) {
        return response()->json([
            'data' => ['available' => false, 'reason' => 'format', 'slug' => $slug],
            'meta' => $meta,
        ]);
    }

    if (SlugValidator::isReserved($slug)) {
        return response()->json([
            'data' => ['available' => false, 'reason' => 'reserved', 'slug' => $slug],
            'meta' => $meta,
        ]);
    }

    $taken = Tenant::withoutGlobalScopes()
        ->where('slug', $slug)
        ->exists();

    if ($taken) {
        return response()->json([
            'data' => ['available' => false, 'reason' => 'taken', 'slug' => $slug],
            'meta' => $meta,
        ]);
    }

    return response()->json([
        'data' => ['available' => true, 'slug' => $slug],
        'meta' => $meta,
    ]);
})->name('api.v1.tenants.check-slug');

// Public: active plans listing for the onboarding wizard plan-selection step.
Route::get('/plans', static function (Request $request): JsonResponse {
    $plans = Plan::where('is_active', true)
        ->orderBy('sort_order')
        ->get();

    $requestId = (string) $request->header('X-Request-Id', '');

    return response()->json([
        'data' => PlanResource::collection($plans)->toArray($request),
        'meta' => ['request_id' => $requestId],
    ]);
})->name('api.v1.plans.index');

Route::middleware('auth:sanctum')->group(static function (): void {
    Route::post('/tenants', TenantProvisionController::class)->name('api.v1.tenants.provision');
});
