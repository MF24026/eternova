<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Api\V1\TenantProvisionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tenants API Routes — /api/v1/tenants/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
|
| POST /api/v1/tenants  — provision a new tenant for the authenticated user.
| Full tenant management (super-admin list, update, suspend, etc.) lands in #12.
*/

Route::middleware('auth:sanctum')->group(static function (): void {
    Route::post('/tenants', TenantProvisionController::class)->name('api.v1.tenants.provision');
});
