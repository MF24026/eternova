<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | PDF Renderer Driver
    |--------------------------------------------------------------------------
    |
    | Controls which backend generates quotation PDFs. The default is
    | 'dompdf' — pure PHP, no headless Chrome or Node required, runs in
    | the existing Docker image without any additional system packages.
    |
    | Future drivers (e.g. 'browsershot' for pixel-perfect Tailwind rendering)
    | can be added to the drivers map and selected here without changing
    | application code. Driver selection is owned by QuotationsServiceProvider.
    |
    | Supported: 'dompdf'
    | Planned:   'browsershot' (requires chromium + node — NOT in current image)
    |
    */
    'renderer' => env('PDF_RENDERER', 'dompdf'),

    /*
    |--------------------------------------------------------------------------
    | Driver class map
    |--------------------------------------------------------------------------
    |
    | Maps driver name → fully-qualified class name implementing
    | QuotationPdfRenderer. QuotationsServiceProvider resolves the active
    | driver from this map and binds it into the container.
    |
    */
    'drivers' => [
        'dompdf' => \App\Modules\Quotations\Pdf\DomPdfRenderer::class,
        // 'browsershot' => \App\Modules\Quotations\Pdf\BrowsershotRenderer::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | DomPDF options
    |--------------------------------------------------------------------------
    |
    | These options are passed directly to DomPDF at render time via the
    | barryvdh/laravel-dompdf facade.
    |
    | paper_size: 'letter' is standard for LatAm business documents (SV/CO/MX).
    | chroot: restricts local file access to the storage path for security.
    | enable_remote: allows DomPDF to fetch external URLs (e.g. a logo_url
    |   that is a full https:// URL). Disabled by default — local logos are
    |   embedded as base64 instead. Enable only when the logo is guaranteed
    |   to be a stable, fast, accessible external URL.
    |
    | Trade-off: enabling remote slows down PDF generation (HTTP request on
    |   every render) and leaks the server IP to the image host. Prefer
    |   local storage + base64 embedding, which is what DomPdfRenderer does.
    |
    */
    'dompdf' => [
        'paper_size'    => env('PDF_PAPER_SIZE', 'letter'),
        'paper_orient'  => env('PDF_PAPER_ORIENT', 'portrait'),
        'enable_remote' => (bool) env('PDF_ENABLE_REMOTE', false),
        'chroot'        => storage_path('app'),
    ],

];
