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
        'api' => (int) env('RATE_LIMIT_API', 120),
        'login' => (int) env('RATE_LIMIT_LOGIN', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Response security headers
    |--------------------------------------------------------------------------
    |
    | Applied by App\Http\Middleware\SecurityHeaders to every response. The
    | static headers (nosniff, frame-options, referrer, permissions) are always
    | sent. CSP and HSTS are toggleable so they can be relaxed in environments
    | where they would get in the way (HSTS bricks plain-http dev; the strict
    | CSP is only used in production — see the middleware).
    |
    */

    'headers' => [
        // Content-Security-Policy. Strict in production (per-request Vite nonce),
        // relaxed in local/CI so Debugbar and the Vite dev client keep working.
        'csp_enabled' => (bool) env('CSP_ENABLED', true),

        // HSTS is only emitted over real TLS, so it is inert on http dev even
        // when enabled. Disable via env only if you terminate TLS upstream and
        // set the header there instead.
        'hsts_enabled' => (bool) env('HSTS_ENABLED', true),
        'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),
    ],

];
