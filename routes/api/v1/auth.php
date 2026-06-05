<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Auth API Routes — /api/v1/auth/*
|--------------------------------------------------------------------------
| Health check is registered here. Login, register, /me, token endpoints
| land in issue #10 (Sanctum auth).
*/

Route::get('/health', static fn () => response()->json([
    'data' => ['status' => 'ok', 'version' => 'v1'],
    'meta' => ['request_id' => request()->headers->get('X-Request-Id')],
]))->name('api.v1.health');
