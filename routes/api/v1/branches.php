<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Controllers\Api\V1\BranchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Branches API Routes — /api/v1/branches
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
| Read-only listing of the current tenant's branches, consumed by the POS
| terminal (branch selector) and the inventory module.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('branches')->group(static function (): void {
    Route::get('/', [BranchController::class, 'index'])
        ->name('api.v1.branches.index');
});
