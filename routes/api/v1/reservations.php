<?php

declare(strict_types=1);

use App\Modules\Reservations\Http\Controllers\Api\V1\ReservationController;
use App\Modules\Reservations\Http\Controllers\Api\V1\ReservationSettingsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Reservations API Routes — /api/v1/reservations/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
| Reservation management, status transitions, payment recording,
| order conversion, and tenant-level settings.
|
| Route ordering: static segments (settings) MUST appear before the
| {reservation} wildcard to prevent Laravel from matching "settings" as
| a reservation id. This is the same rule applied in the Orders module.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('reservations')->group(static function (): void {
    // ── Static paths — must be declared BEFORE the {reservation} wildcard ───
    Route::get('/settings', [ReservationSettingsController::class, 'show'])
        ->name('api.v1.reservations.settings.show');

    Route::put('/settings', [ReservationSettingsController::class, 'update'])
        ->name('api.v1.reservations.settings.update');

    // ── Collection + capture ─────────────────────────────────────────────────
    Route::get('/', [ReservationController::class, 'index'])
        ->name('api.v1.reservations.index');

    Route::post('/', [ReservationController::class, 'store'])
        ->name('api.v1.reservations.store');

    // ── Sub-actions on a single reservation ─────────────────────────────────
    Route::patch('/{reservation}/status', [ReservationController::class, 'transition'])
        ->name('api.v1.reservations.transition');

    Route::patch('/{reservation}/assignee', [ReservationController::class, 'assign'])
        ->name('api.v1.reservations.assignee');

    Route::post('/{reservation}/payments', [ReservationController::class, 'recordPayment'])
        ->name('api.v1.reservations.payments.record');

    Route::post('/{reservation}/convert', [ReservationController::class, 'convert'])
        ->name('api.v1.reservations.convert');

    Route::post('/{reservation}/cancel', [ReservationController::class, 'cancel'])
        ->name('api.v1.reservations.cancel');

    // ── Single-resource show — declared last to avoid shadowing sub-paths ────
    Route::get('/{reservation}', [ReservationController::class, 'show'])
        ->name('api.v1.reservations.show');
});
