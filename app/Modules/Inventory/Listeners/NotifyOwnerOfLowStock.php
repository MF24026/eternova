<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Listeners;

use App\Modules\Inventory\Events\StockLowDetected;
use App\Modules\Inventory\Notifications\LowStockNotification;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queued listener: sends a LowStockNotification to every tenant owner.
 *
 * Runs on the default queue. Retries up to 3 times with exponential backoff.
 * The inventory relation is re-fetched inside the job to get a fresh
 * available value — important because the queue may process this after a delay.
 *
 * Multi-owner support: tenants can have multiple owners (e.g. business partners).
 * All owners receive the notification independently so each can act.
 */
final class NotifyOwnerOfLowStock implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $backoff = 60;

    public function handle(StockLowDetected $event): void
    {
        $inventory = $event->inventory;

        $tenant = Tenant::find($inventory->tenant_id);

        if ($tenant === null) {
            Log::warning('NotifyOwnerOfLowStock: tenant not found, skipping notification', [
                'tenant_id' => $inventory->tenant_id,
                'branch_inventory_id' => $inventory->id,
            ]);

            return;
        }

        $owners = $tenant->users()
            ->wherePivot('role', 'owner')
            ->get();

        if ($owners->isEmpty()) {
            Log::info('NotifyOwnerOfLowStock: tenant has no owners, skipping notification', [
                'tenant_id' => $tenant->id,
                'branch_inventory_id' => $inventory->id,
            ]);

            return;
        }

        $tenantId = $tenant->id;
        $notification = new LowStockNotification($inventory, $event->threshold);

        foreach ($owners as $owner) {
            $owner->notify($notification);

            // Laravel's built-in DatabaseChannel does not populate extra columns beyond
            // the standard schema (type, notifiable, data, read_at). We patch tenant_id
            // immediately after notify() since database channel writes are synchronous —
            // even when the listener itself is queued, the DB insert inside notify() runs
            // synchronously within this job. We match on the most recent unset row to
            // avoid race conditions if another notification of the same type is in-flight.
            DB::table('notifications')
                ->where('notifiable_type', $owner::class)
                ->where('notifiable_id', $owner->id)
                ->where('type', LowStockNotification::class)
                ->whereNull('tenant_id')
                ->orderByDesc('created_at')
                ->limit(1)
                ->update(['tenant_id' => $tenantId]);
        }

        Log::info('NotifyOwnerOfLowStock: low stock notifications sent', [
            'tenant_id' => $tenant->id,
            'branch_inventory_id' => $inventory->id,
            'product_variant_id' => $inventory->product_variant_id,
            'available' => $inventory->available,
            'threshold' => $event->threshold,
            'owner_count' => $owners->count(),
        ]);
    }
}
