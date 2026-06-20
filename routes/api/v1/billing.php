<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\Api\V1\AccountBillingController;
use App\Modules\Billing\Http\Controllers\Api\V1\CancelSubscriptionController;
use App\Modules\Billing\Http\Controllers\Api\V1\ChangePlanController;
use App\Modules\Billing\Http\Controllers\Api\V1\InvoiceController;
use App\Modules\Billing\Http\Controllers\Api\V1\SubscribeController;
use App\Modules\Billing\Http\Controllers\Api\V1\WompiWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing API Routes — /api/v1/billing/* and /api/v1/account/billing/*
|--------------------------------------------------------------------------
| Prefixed with /api/v1 by bootstrap/app.php.
*/

// Wompi webhook receiver. PUBLIC by design (server-to-server) — the HMAC check inside the
// controller is the only trust boundary. No auth/tenant middleware.
Route::post('/billing/webhooks/wompi', WompiWebhookController::class)
    ->name('api.v1.billing.webhooks.wompi');

// Tenant-facing billing (Owner-only — enforced by the billing.manage gate in each controller).
Route::middleware(['auth:sanctum', 'tenant'])
    ->prefix('/account/billing')
    ->name('api.v1.account.billing.')
    ->group(static function (): void {
        Route::get('/', AccountBillingController::class)->name('overview');
        Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])
            ->whereNumber('invoice')->name('invoices.download');
        Route::post('/cancel', CancelSubscriptionController::class)->name('cancel');
        Route::post('/plan', ChangePlanController::class)->name('plan');
        Route::post('/subscribe', SubscribeController::class)->name('subscribe');
    });
