<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Receipt storage disk
    |--------------------------------------------------------------------------
    |
    | Controls which filesystem disk receives uploaded receipt files.
    | In dev this defaults to "public" so files are accessible without S3.
    | In production, set EXPENSES_RECEIPT_DISK=s3.
    |
    | Files are stored under tenants/{tenant_id}/receipts/{ulid}.{ext}
    | so each tenant's receipts are isolated at the path level even within
    | a shared bucket.
    |
    */
    'receipt_disk' => env('EXPENSES_RECEIPT_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Receipt upload constraints
    |--------------------------------------------------------------------------
    |
    | Max bytes for a single receipt upload (10 MB default — covers both
    | high-res photos and multi-page PDFs from typical business receipts).
    | Keep aligned with the validation rule max:{$kb} in StoreReceiptRequest.
    |
    */
    'receipt_max_bytes' => (int) env('EXPENSES_RECEIPT_MAX_BYTES', 10 * 1024 * 1024),

];
