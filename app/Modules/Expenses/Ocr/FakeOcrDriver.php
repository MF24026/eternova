<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Ocr;

/**
 * Deterministic stub OCR driver for test suites and local development
 * without Tesseract installed.
 *
 * Returns a fixed canonical OcrResult regardless of the input file. This
 * makes PHPUnit + Playwright tests fast, reproducible, and Tesseract-free.
 * CI always binds this driver by forcing OCR_DRIVER=fake in phpunit.xml.
 *
 * The canonical values here are intentionally recognisable (not zeros, not
 * real-looking data) so that test assertions can confirm the driver was
 * actually invoked and its output stored correctly.
 */
final class FakeOcrDriver implements OcrDriverInterface
{
    /** Fixed vendor returned by the fake — matches ReceiptTextParserTest fixture naming. */
    public const VENDOR = 'Proveedor Demo S.A.';

    /** Fixed amount in centavos: $123.45 */
    public const AMOUNT_CENTS = 12345;

    /** Fixed ISO date */
    public const DATE = '2026-01-15';

    /** Fixed raw text — includes a TOTAL line so E3 can assert ocr_data fields. */
    public const RAW_TEXT = <<<'TEXT'
        PROVEEDOR DEMO S.A.
        NIT: 123456789-0
        Fecha: 15/01/2026
        -------------------
        Producto A         $100.00
        Producto B          $23.45
        -------------------
        SUBTOTAL            $123.45
        TOTAL               $123.45
        TEXT;

    public function extract(string $absolutePath): OcrResult
    {
        return new OcrResult(
            rawText:     self::RAW_TEXT,
            vendor:      self::VENDOR,
            amountCents: self::AMOUNT_CENTS,
            date:        self::DATE,
            confidence:  null,
            lineItems:   [],
        );
    }
}
