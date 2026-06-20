<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway driver
    |--------------------------------------------------------------------------
    |
    | Which PaymentGatewayInterface implementation to bind. Defaults to 'fake' so the
    | whole billing stack runs locally and in CI without any real credentials (mirrors the
    | OCR fake-driver pattern). Switch to 'wompi' once the sandbox/production keys below are
    | populated.
    |
    | Supported: 'fake', 'wompi'.
    */
    'driver' => env('BILLING_DRIVER', 'fake'),

    /*
    |--------------------------------------------------------------------------
    | Wompi credentials
    |--------------------------------------------------------------------------
    |
    | Never commit real values. This is Wompi SV (El Salvador), not Colombia, and it uses
    | OAuth2 client_credentials — NOT a public/private key pair:
    |   - app_id     = client_id   (the business "App ID")
    |   - api_secret = client_secret AND the `wompi_hash` webhook HMAC key (the "API Secret")
    | The server fetches a Bearer token from auth_url/connect/token, then calls base_url. There
    | is NO separate sandbox host — QA uses the app in "non-productive" mode on the purchase
    | endpoint. See docs/billing/wompi-sv-integration.md.
    */
    'wompi' => [
        'env' => env('WOMPI_ENV', 'production'),
        'auth_url' => env('WOMPI_AUTH_URL', 'https://id.wompi.sv'),
        'base_url' => env('WOMPI_BASE_URL', 'https://api.wompi.sv'),
        'app_id' => env('WOMPI_APP_ID', ''),
        'api_secret' => env('WOMPI_API_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit breaker
    |--------------------------------------------------------------------------
    |
    | Open a gateway call type after this many consecutive failures, staying open for the
    | cooldown. Use SEPARATE keys per call type so one broken endpoint does not block others.
    */
    'circuit' => [
        'threshold' => (int) env('BILLING_CIRCUIT_THRESHOLD', 5),
        'cooldown_seconds' => (int) env('BILLING_CIRCUIT_COOLDOWN', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lifecycle windows
    |--------------------------------------------------------------------------
    */
    'trial_days' => (int) env('BILLING_TRIAL_DAYS', 14),
    'dunning_retry_days' => array_map('intval', explode(',', env('BILLING_DUNNING_RETRY_DAYS', '3,7,14'))),
    'grace_after_suspend_days' => (int) env('BILLING_GRACE_AFTER_SUSPEND_DAYS', 30),
    'hard_delete_after_days' => (int) env('BILLING_HARD_DELETE_AFTER_DAYS', 180),
    'log_retention_days' => (int) env('BILLING_LOG_RETENTION_DAYS', 1825),

];
