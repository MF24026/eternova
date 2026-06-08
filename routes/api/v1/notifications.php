<?php

declare(strict_types=1);

use App\Modules\Inventory\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Notifications API Routes — /api/v1/notifications/*
|--------------------------------------------------------------------------
| Per-user, per-tenant notification endpoints.
|
| Route ordering: static segments (unread-count, read-all) must appear
| BEFORE the {id} wildcard to avoid misrouting.
*/

Route::middleware(['auth:sanctum', 'tenant'])->prefix('notifications')->group(static function (): void {
    Route::get('/unread-count', [NotificationController::class, 'unreadCount'])
        ->name('api.v1.notifications.unread-count');

    Route::get('/', [NotificationController::class, 'index'])
        ->name('api.v1.notifications.index');

    Route::patch('/read-all', [NotificationController::class, 'markAllAsRead'])
        ->name('api.v1.notifications.read-all');

    Route::patch('/{id}/read', [NotificationController::class, 'markAsRead'])
        ->name('api.v1.notifications.mark-read');
});
