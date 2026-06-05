<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Same-subdomain SPA + API means we rarely need CORS. We still configure it
    | broadly in development and lock it down when custom domains land (Sprint 8+).
    |
    | supports_credentials: true is required for Sanctum SPA cookie auth to work
    | when the SPA origin and API origin are the same domain/subdomain.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-Id', 'X-Tenant-Trial-Expired'],

    'max_age' => 0,

    'supports_credentials' => true,

];
