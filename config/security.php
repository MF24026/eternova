<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | Used by the named limiters in AppServiceProvider:
    |   - 'api'   → global guard on every /api/v1 route (per user or IP)
    |   - 'login' → brute-force guard on POST /auth/login (per email + IP)
    |
    | Defaults are intentionally LOW (production-safe). Raise them via env in
    | local dev and CI so the SPA, manual testing and the e2e suite — which fire
    | many requests from a single IP — are not throttled. Leave them unset in
    | production to get the secure defaults.
    |
    */

    'rate_limits' => [
        'api'   => (int) env('RATE_LIMIT_API', 120),
        'login' => (int) env('RATE_LIMIT_LOGIN', 5),
    ],

];
