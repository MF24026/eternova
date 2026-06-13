<?php

declare(strict_types=1);

use App\Modules\Dashboard\Http\Controllers\Api\V1\DashboardController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Dashboard API Routes — /api/v1/dashboard
|--------------------------------------------------------------------------
| Prefixed with /api/v1 by bootstrap/app.php. Tenant KPI summary for the
| admin panel. Read-only; scoped to the current tenant.
*/

Route::middleware(['auth:sanctum', 'tenant'])->group(static function (): void {
    Route::get('/dashboard', DashboardController::class)
        ->name('api.v1.dashboard');
});
