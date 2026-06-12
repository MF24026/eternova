<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Ocr;

use RuntimeException;

/**
 * Thrown when an OCR driver fails to process a file.
 *
 * The calling job (ProcessReceiptOcrJob) catches this to mark the
 * expense ocr_status=failed and release the job slot without retrying
 * indefinitely on a file that will never parse.
 *
 * Callers should NOT catch the parent RuntimeException to handle OCR
 * failures — always catch OcrException specifically so that unexpected
 * runtime errors in the driver still surface as bugs.
 */
final class OcrException extends RuntimeException {}
