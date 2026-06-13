<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Pdf;

use RuntimeException;

/**
 * Raised by PDF renderer implementations when the document cannot be generated.
 *
 * The controller catches this and returns a 500 with a safe user-facing
 * message; the original context is preserved via $previous so logs capture it.
 */
final class PdfException extends RuntimeException {}
