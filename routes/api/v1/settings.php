<?php

declare(strict_types=1);

use App\Modules\Settings\Http\Controllers\Api\V1\SettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Settings API Routes — /api/v1/settings/*
|--------------------------------------------------------------------------
| Prefixed with /api/v1 by bootstrap/app.php. Tenant configuration:
|   GET  /settings          → all groups resolved + catalog meta
|   POST /settings/{group}  → update one group (POST supports multipart uploads)
|
| Group is validated against SettingsService::isKnownGroup() in the controller.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('settings')->group(static function (): void {
    Route::get('/', [SettingsController::class, 'show'])
        ->name('api.v1.settings.show');

    Route::post('/{group}', [SettingsController::class, 'update'])
        ->name('api.v1.settings.update')
        ->where('group', '[a-z]+');
});
