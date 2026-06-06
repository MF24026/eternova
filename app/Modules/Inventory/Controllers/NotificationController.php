<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Resources\NotificationCollection;
use App\Modules\Inventory\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * REST endpoints for per-user, per-tenant notifications.
 *
 * All queries filter by BOTH the authenticated user (notifiable_id) AND the
 * current tenant_id so a user who belongs to multiple tenants only sees
 * notifications from the tenant they are currently acting as.
 */
final class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications/unread-count
     *
     * Returns the count of unread notifications for the current user in the
     * current tenant context.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = current_tenant();

        $count = DatabaseNotification::query()
            ->where('notifiable_type', $user::class)
            ->where('notifiable_id', $user->id)
            ->where('tenant_id', $tenant?->id)
            ->whereNull('read_at')
            ->count();

        return response()->json([
            'data' => ['count' => $count],
            'meta' => [
                'tenant_id' => $tenant?->id,
                'request_id' => $request->header('X-Request-Id', ''),
            ],
        ]);
    }

    /**
     * GET /api/v1/notifications
     *
     * Paginated list of notifications for the current user + tenant.
     * Supports ?unread_only=true to filter to unread notifications only.
     */
    public function index(Request $request): NotificationCollection
    {
        $user = $request->user();
        $tenant = current_tenant();

        $query = DatabaseNotification::query()
            ->where('notifiable_type', $user::class)
            ->where('notifiable_id', $user->id)
            ->where('tenant_id', $tenant?->id)
            ->orderByDesc('created_at');

        if ($request->boolean('unread_only')) {
            $query->whereNull('read_at');
        }

        $perPage = min((int) ($request->integer('per_page', 20)), 100);

        return new NotificationCollection($query->paginate($perPage));
    }

    /**
     * PATCH /api/v1/notifications/{id}/read
     *
     * Mark a single notification as read. Returns 404 if the notification
     * does not belong to the current user + tenant.
     */
    public function markAsRead(Request $request, string $id): NotificationResource
    {
        $user = $request->user();
        $tenant = current_tenant();

        $notification = DatabaseNotification::query()
            ->where('id', $id)
            ->where('notifiable_type', $user::class)
            ->where('notifiable_id', $user->id)
            ->where('tenant_id', $tenant?->id)
            ->firstOrFail();

        $notification->markAsRead();

        return new NotificationResource($notification);
    }

    /**
     * PATCH /api/v1/notifications/read-all
     *
     * Mark all unread notifications as read for the current user + tenant.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = current_tenant();

        $updated = DatabaseNotification::query()
            ->where('notifiable_type', $user::class)
            ->where('notifiable_id', $user->id)
            ->where('tenant_id', $tenant?->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'data' => ['marked_read' => $updated],
            'meta' => [
                'tenant_id' => $tenant?->id,
                'request_id' => $request->header('X-Request-Id', ''),
            ],
        ]);
    }
}
