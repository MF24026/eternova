<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\Api\V1\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Team API Routes — /api/v1/team
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
| Read-only listing of the current tenant's members, consumed by staff
| assignment selectors (e.g. the order assignee picker).
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('team')->group(static function (): void {
    Route::get('/', [TeamController::class, 'index'])
        ->name('api.v1.team.index');
});
