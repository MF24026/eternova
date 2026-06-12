<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Ocr;

/**
 * Contract for OCR backends.
 *
 * Implementations are responsible for:
 *   1. Accepting any supported file type (JPEG, PNG, PDF).
 *   2. Producing an OcrResult with best-effort structured fields.
 *   3. Raising OcrException on unrecoverable failures so the
 *      calling job can mark the expense ocr_status=failed.
 *
 * The interface is intentionally minimal — a single method — so that
 * cloud drivers (Textract, Mindee) can be plugged in later without
 * touching application code. Driver selection is owned by config/ocr.php.
 */
interface OcrDriverInterface
{
    /**
     * Extract text and structured fields from an image or PDF file.
     *
     * @param  string $absolutePath  Absolute filesystem path to the receipt file.
     *                               For PDFs the driver handles conversion to image
     *                               internally before running OCR.
     * @return OcrResult             Populated with best-effort suggestions.
     *
     * @throws OcrException          On any unrecoverable extraction failure.
     */
    public function extract(string $absolutePath): OcrResult;
}
