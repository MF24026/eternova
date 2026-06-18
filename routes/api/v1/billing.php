<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\Api\V1\WompiWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing API Routes — /api/v1/billing/*
|--------------------------------------------------------------------------
| Prefixed with /api/v1 by bootstrap/app.php. Subscription + invoice read
| endpoints land with the Phase 6 tenant UI.
*/

// Wompi webhook receiver. PUBLIC by design (server-to-server) — the HMAC check inside the
// controller is the only trust boundary. No auth/tenant middleware.
Route::post('/billing/webhooks/wompi', WompiWebhookController::class)
    ->name('api.v1.billing.webhooks.wompi');
