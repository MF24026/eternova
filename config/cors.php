<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | supports_credentials: true is required for Sanctum SPA cookie auth.
    | The CORS spec forbids `Access-Control-Allow-Origin: *` when credentials
    | are enabled — browsers will reject the response entirely. We therefore
    | keep allowed_origins empty and use allowed_origins_patterns with explicit
    | regex rules covering every legitimate origin (dev + prod subdomains).
    |
    | To allow additional origins without a code change (e.g. custom tenant
    | domains in Sprint 10), set CORS_EXTRA_ORIGIN_PATTERNS in .env as a
    | comma-separated list of regex patterns.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Must remain empty — wildcard origin is incompatible with credentials.
    'allowed_origins' => [],

    'allowed_origins_patterns' => array_merge(
        [
            '#^https?://localhost(:\d+)?$#',
            '#^https?://127\.0\.0\.1(:\d+)?$#',
            '#^https?://[\w-]+\.eternova\.localhost(:\d+)?$#',
            '#^https?://[\w-]+\.eternova\.test(:\d+)?$#',
            '#^https://eternova\.app$#',
            '#^https://[\w-]+\.eternova\.app$#',
        ],
        array_filter(explode(',', env('CORS_EXTRA_ORIGIN_PATTERNS', '')))
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id', 'X-Tenant-Trial-Expired'],

    'max_age' => 0,

    'supports_credentials' => true,

];
