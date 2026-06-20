<?php

declare(strict_types=1);

use App\Modules\SuperAdmin\Http\Controllers\Api\V1\SuperAdminBillingActionController;
use App\Modules\SuperAdmin\Http\Controllers\Api\V1\SuperAdminBillingMetricsController;
use App\Modules\SuperAdmin\Http\Controllers\Api\V1\SuperAdminTenantController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SuperAdmin API Routes — /api/v1/super-admin/*
|--------------------------------------------------------------------------
| The SaaS operator console (served at admin.eternova.app). Prefixed with /api/v1 by
| bootstrap/app.php. Gated by the `super_admin` middleware (the is_super_admin boolean — a
| separate trust axis from tenant RBAC). NOT tenant-scoped: these endpoints read/act across all
| tenants. No `tenant` middleware here by design.
*/

Route::middleware(['auth:sanctum', 'super_admin'])
    ->prefix('super-admin')
    ->name('api.v1.super-admin.')
    ->group(static function (): void {
        // ── Read ─────────────────────────────────────────────────────────────
        Route::get('/billing/metrics', SuperAdminBillingMetricsController::class)->name('billing.metrics');
        Route::get('/tenants', [SuperAdminTenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/{tenant}', [SuperAdminTenantController::class, 'show'])->name('tenants.show');

        // ── Mutating operator actions (each requires a reason; audited; tighter rate limit) ──
        Route::middleware('throttle:20,1')->group(static function (): void {
            Route::post('/tenants/{tenant}/extend-trial', [SuperAdminBillingActionController::class, 'extendTrial'])
                ->name('tenants.extend-trial');
            Route::post('/tenants/{tenant}/suspend', [SuperAdminBillingActionController::class, 'suspend'])
                ->name('tenants.suspend');
            Route::post('/tenants/{tenant}/reactivate', [SuperAdminBillingActionController::class, 'reactivate'])
                ->name('tenants.reactivate');
        });
    });
