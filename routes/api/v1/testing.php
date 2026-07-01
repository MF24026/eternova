<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Test-support routes — E2E seeding hooks. NEVER available in production.
|--------------------------------------------------------------------------
| The dev/e2e server runs the queue on redis, so real notifications are not
| written synchronously and cannot be asserted deterministically. These
| endpoints let Playwright seed DatabaseNotification rows directly for the
| authenticated user + current tenant. Guarded twice: the whole file returns
| early outside local/testing, and the handler re-checks.
*/

if (! app()->environment(['local', 'testing'])) {
    return;
}

Route::middleware(['auth:sanctum', 'tenant'])->prefix('testing')->group(function (): void {
    Route::post('/notifications', function (Request $request) {
        abort_unless(app()->environment(['local', 'testing']), 404);

        $user = $request->user();
        $tenant = current_tenant();

        $payloads = [
            ['type' => 'inventory.low_stock', 'data' => [
                'type' => 'inventory.low_stock',
                'product_name' => 'Rosa Eterna',
                'sku' => 'ROSA-001',
                'branch_name' => 'Sucursal Centro',
                'available' => 2,
                'threshold' => 5,
            ]],
            ['type' => 'billing.invoice_ready', 'data' => [
                'type' => 'billing.invoice_ready',
                'number' => 'INV-1001',
                'total_cents' => 2599,
                'currency' => 'USD',
            ]],
        ];

        $ids = [];
        foreach ($payloads as $p) {
            $ids[] = DatabaseNotification::create([
                'id' => (string) Str::uuid(),
                'type' => $p['type'],
                'notifiable_type' => $user::class,
                'notifiable_id' => $user->id,
                'data' => $p['data'],
                'read_at' => null,
                'tenant_id' => $tenant?->id,
            ])->id;
        }

        return response()->json(['data' => ['created' => count($ids), 'ids' => $ids]]);
    });
});
