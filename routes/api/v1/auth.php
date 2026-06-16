<?php

declare(strict_types=1);

use App\Modules\Auth\Http\Controllers\Api\V1\ForgotPasswordController;
use App\Modules\Auth\Http\Controllers\Api\V1\LoginController;
use App\Modules\Auth\Http\Controllers\Api\V1\LogoutController;
use App\Modules\Auth\Http\Controllers\Api\V1\MeController;
use App\Modules\Auth\Http\Controllers\Api\V1\RegisterController;
use App\Modules\Auth\Http\Controllers\Api\V1\ResetPasswordController;
use App\Modules\Auth\Http\Controllers\Api\V1\TokenController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth API Routes — /api/v1/*
|--------------------------------------------------------------------------
| All routes in this file are prefixed with /api/v1 by bootstrap/app.php.
|
| GET /sanctum/csrf-cookie is auto-registered by Sanctum — no manual route needed.
*/

// ── Health (unauthenticated) ─────────────────────────────────────────────────
Route::get('/health', static fn () => response()->json([
    'data' => ['status' => 'ok', 'version' => 'v1'],
    'meta' => ['request_id' => request()->headers->get('X-Request-Id')],
]))->name('api.v1.health');

// ── Registration + Login (unauthenticated) ───────────────────────────────────
Route::post('/auth/register', RegisterController::class)->name('api.v1.auth.register');
Route::post('/auth/login', [LoginController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.v1.auth.login');
Route::post('/auth/token', [TokenController::class, 'issue'])->name('api.v1.auth.token');

// ── Password reset (unauthenticated, throttled) ──────────────────────────────
Route::post('/auth/forgot-password', ForgotPasswordController::class)
    ->middleware('throttle:6,1')
    ->name('api.v1.auth.forgot-password');
Route::post('/auth/reset-password', ResetPasswordController::class)
    ->middleware('throttle:6,1')
    ->name('api.v1.auth.reset-password');

// ── Authenticated routes ─────────────────────────────────────────────────────
Route::middleware('auth:sanctum')->group(static function (): void {
    Route::post('/auth/logout', LogoutController::class)->name('api.v1.auth.logout');

    Route::get('/me', MeController::class)->name('api.v1.me');

    Route::prefix('/auth/tokens')->name('api.v1.auth.tokens.')->group(static function (): void {
        Route::get('/', [TokenController::class, 'index'])->name('index');
        Route::delete('/{tokenId}', [TokenController::class, 'revoke'])
            ->whereNumber('tokenId')
            ->name('revoke');
    });
});
