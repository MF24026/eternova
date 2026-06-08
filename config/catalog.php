<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Image storage disk
    |--------------------------------------------------------------------------
    |
    | ADR-006: All product images are stored via Laravel's filesystem
    | abstraction. In dev this defaults to the "public" disk
    | (storage/app/public). In production set CATALOG_IMAGE_DISK=s3.
    |
    | Path convention: tenants/{tenant_id}/products/{product_id}/{size}/{uuid}.{ext}
    | where {size} is one of: thumbnail, medium, full.
    |
    */
    'image_disk' => env('CATALOG_IMAGE_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Image sizes (pre-generated at upload time)
    |--------------------------------------------------------------------------
    |
    | ADR-007: Three sizes are generated at upload time using Intervention
    | Image (cover crop, square). Pre-generating is a deliberate trade-off:
    | it costs more storage (~3x) but eliminates per-request CPU cost and
    | makes URLs predictable and CDN-cacheable. On-the-fly resizing is
    | cheaper on storage but introduces per-request latency and is harder
    | to cache correctly at the edge.
    |
    | Values are the side length in pixels (square crop).
    |
    */
    'image_sizes' => [
        'thumbnail' => 200,
        'medium' => 600,
        'full' => 1200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload constraints
    |--------------------------------------------------------------------------
    */
    'image_max_bytes' => 5 * 1024 * 1024, // 5 MB
    'image_allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    'image_min_dimension' => 400, // px — shortest side before resize

];
