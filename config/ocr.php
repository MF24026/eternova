<?php

declare(strict_types=1);
use App\Modules\Expenses\Ocr\FakeOcrDriver;
use App\Modules\Expenses\Ocr\TesseractOcrDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | OCR Driver
    |--------------------------------------------------------------------------
    |
    | Controls which OCR backend processes receipt images. The default is
    | 'tesseract' (self-hosted, free, private). Set to 'fake' locally when
    | Tesseract is not installed, or in CI/tests — the fake driver returns
    | a deterministic canonical result without running any external process.
    |
    | Future drivers (e.g. 'textract', 'mindee') can be added to the drivers
    | map and gated by subscription plan without changing application code.
    |
    | Supported: 'tesseract', 'fake'
    |
    */
    'driver' => env('OCR_DRIVER', 'tesseract'),

    /*
    |--------------------------------------------------------------------------
    | Driver class map
    |--------------------------------------------------------------------------
    |
    | Maps driver name → fully-qualified class name implementing
    | OcrDriverInterface. ExpensesServiceProvider resolves the active driver
    | from this map and binds it into the container.
    |
    */
    'drivers' => [
        'tesseract' => TesseractOcrDriver::class,
        'fake' => FakeOcrDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Tesseract options
    |--------------------------------------------------------------------------
    |
    | Language(s) passed to the -l flag. 'spa+eng' covers Spanish-primary
    | receipts with occasional English text (product names, software labels).
    | Timeout is a hard ceiling on the Tesseract process to prevent runaway
    | jobs; 60 s is generous for a single-page receipt image.
    |
    */
    'tesseract' => [
        'binary' => env('TESSERACT_BINARY', 'tesseract'),
        'languages' => env('TESSERACT_LANGUAGES', 'spa+eng'),
        'timeout' => (int) env('TESSERACT_TIMEOUT_SECONDS', 60),
    ],

];
