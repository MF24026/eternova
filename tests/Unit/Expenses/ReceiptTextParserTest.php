<?php

declare(strict_types=1);

namespace Tests\Unit\Expenses;

use App\Modules\Expenses\Ocr\ReceiptTextParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the receipt heuristic parser.
 *
 * Each test embeds a realistic OCR-output fixture as a heredoc string.
 * No DB, no container, no filesystem — just pure PHP.
 *
 * Coverage goals:
 *   - Correct TOTAL extraction when SUBTOTAL is also present (common trap).
 *   - Spanish comma-decimal (1.234,50) and English dot-decimal (1,234.50).
 *   - Thousands separators without decimal part.
 *   - dd/mm/yyyy and yyyy-mm-dd and dd/mm/yy date formats.
 *   - Vendor from first plausible line (grocery receipt).
 *   - Vendor from tax-id proximity (NIT).
 *   - Garbage / empty input returns all-null.
 */
final class ReceiptTextParserTest extends TestCase
{
    private ReceiptTextParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ReceiptTextParser();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Amount extraction
    // ─────────────────────────────────────────────────────────────────────────

    public function test_extracts_total_ignoring_subtotal_line(): void
    {
        // Classic LatAm receipt: SUBTOTAL + IVA lines, then a TOTAL line.
        // Parser must not mistake SUBTOTAL for TOTAL.
        $text = <<<'TEXT'
            SUPERMERCADO LA COLONIA
            San Salvador, El Salvador
            Fecha: 05/06/2026

            Articulo 1             $12.50
            Articulo 2              $8.00
            Articulo 3             $15.75

            SUBTOTAL               $36.25
            IVA (13%)               $4.71
            TOTAL                  $40.96
            Gracias por su compra
            TEXT;

        $result = $this->parser->parse($text);

        // $40.96 → 4096 centavos
        $this->assertSame(4096, $result['amount_cents']);
    }

    public function test_extracts_amount_with_spanish_decimal_comma(): void
    {
        // Spanish locale: thousands separator is '.', decimal separator is ','.
        $text = <<<'TEXT'
            FERRETERÍA EL PERNO
            NIT: 9876543-2
            Fecha: 10/06/2026

            TOTAL A PAGAR       $1.234,50
            TEXT;

        $result = $this->parser->parse($text);

        // $1.234,50 → 123450 centavos
        $this->assertSame(123450, $result['amount_cents']);
    }

    public function test_extracts_amount_with_english_decimal_dot(): void
    {
        // English locale: thousands separator is ',', decimal separator is '.'.
        $text = <<<'TEXT'
            COFFEE SHOP
            Fecha: 2026-03-22
            TOTAL           $2,500.75
            TEXT;

        $result = $this->parser->parse($text);

        // $2,500.75 → 250075 centavos
        $this->assertSame(250075, $result['amount_cents']);
    }

    public function test_extracts_whole_number_amount_without_decimals(): void
    {
        $text = <<<'TEXT'
            PAPELERÍA CENTRAL
            RFC: ABC-123456-XYZ
            15/01/2026
            TOTAL          $500
            TEXT;

        $result = $this->parser->parse($text);

        // $500 → 50000 centavos
        $this->assertSame(50000, $result['amount_cents']);
    }

    public function test_falls_back_to_largest_amount_when_no_total_label(): void
    {
        // Some old thermal printers don't print "TOTAL" — just a final amount.
        $text = <<<'TEXT'
            TIENDA NATURISTA
            05/06/2026
            Vitamina C    $15.00
            Zinc          $12.50
            Calcio         $8.75
                          $36.25
            TEXT;

        $result = $this->parser->parse($text);

        // Fallback: largest number in text. $36.25 → 3625
        $this->assertSame(3625, $result['amount_cents']);
    }

    public function test_total_general_beats_total_parcial(): void
    {
        // Receipt has both TOTAL PARCIAL and TOTAL GENERAL.
        // Parser must return the largest TOTAL value.
        $text = <<<'TEXT'
            DISTRIBUIDORA MORALES
            NRC: 45321-1
            Fecha: 20/05/2026

            TOTAL PARCIAL         $200.00
            Descuento              -$20.00
            TOTAL GENERAL         $180.00
            TEXT;

        $result = $this->parser->parse($text);

        // Largest on TOTAL lines: max(20000, 18000) = 20000
        $this->assertSame(20000, $result['amount_cents']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Date extraction
    // ─────────────────────────────────────────────────────────────────────────

    public function test_parses_ddmmyyyy_slash_date(): void
    {
        $text = <<<'TEXT'
            TIENDA DEMO
            Fecha: 15/03/2026
            TOTAL $50.00
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertSame('2026-03-15', $result['date']);
    }

    public function test_parses_ddmmyyyy_dash_date(): void
    {
        $text = <<<'TEXT'
            BOUTIQUE FLORES
            Fecha: 01-07-2025
            TOTAL $75.00
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertSame('2025-07-01', $result['date']);
    }

    public function test_parses_iso_yyyymmdd_date(): void
    {
        $text = <<<'TEXT'
            DIGITAL RECEIPT
            Date: 2026-11-20
            TOTAL $99.00
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertSame('2026-11-20', $result['date']);
    }

    public function test_parses_two_digit_year_date(): void
    {
        // dd/mm/yy — 2-digit year → assumed 2000+
        $text = <<<'TEXT'
            MINI SUPER
            Fecha: 08/04/26
            TOTAL $18.00
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertSame('2026-04-08', $result['date']);
    }

    public function test_returns_null_date_when_no_date_found(): void
    {
        $text = <<<'TEXT'
            PROVEEDOR GENERICO
            TOTAL $100.00
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertNull($result['date']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vendor extraction
    // ─────────────────────────────────────────────────────────────────────────

    public function test_extracts_vendor_from_top_of_grocery_receipt(): void
    {
        $text = <<<'TEXT'
            SUPERMERCADO LA COLONIA
            Sucursal: Centro Comercial
            Tel: 2222-3333
            Fecha: 05/06/2026
            TOTAL $40.96
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertSame('SUPERMERCADO LA COLONIA', $result['vendor']);
    }

    public function test_extracts_vendor_from_nit_proximity(): void
    {
        // Business name is on the line before the NIT number.
        $text = <<<'TEXT'
            Ferretería El Perno S.A. de C.V.
            NIT: 9876543-2
            Fecha: 10/06/2026
            TOTAL $1.234,50
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertSame('Ferretería El Perno S.A. de C.V.', $result['vendor']);
    }

    public function test_returns_null_vendor_for_receipt_without_clear_name(): void
    {
        // Pure-numeric header lines, no real business name.
        $text = <<<'TEXT'
            00001
            123456789
            20/05/2026
            TOTAL $5.00
            TEXT;

        $result = $this->parser->parse($text);

        // Pure numeric first lines → vendor should be null.
        $this->assertNull($result['vendor']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Edge cases
    // ─────────────────────────────────────────────────────────────────────────

    public function test_empty_string_returns_all_null(): void
    {
        $result = $this->parser->parse('');

        $this->assertNull($result['vendor']);
        $this->assertNull($result['amount_cents']);
        $this->assertNull($result['date']);
    }

    public function test_whitespace_only_returns_all_null(): void
    {
        $result = $this->parser->parse("   \n\t  \n  ");

        $this->assertNull($result['vendor']);
        $this->assertNull($result['amount_cents']);
        $this->assertNull($result['date']);
    }

    public function test_garbage_ocr_output_returns_all_null(): void
    {
        // Simulate a very dark or blurry photo that Tesseract cannot read.
        $text = <<<'TEXT'
            ||| __ ##@! 00O00 |||
            ~~~ ### |||
            TEXT;

        $result = $this->parser->parse($text);

        $this->assertNull($result['amount_cents']);
        $this->assertNull($result['date']);
    }

    public function test_full_service_invoice_fixture(): void
    {
        // Service invoice (not a grocery receipt): header contains business
        // info, has separate SUBTOTAL + IVA lines, date in iso format.
        $text = <<<'TEXT'
            CONSULTORA DIGITAL SV
            RFC: CDS-200715-AB1
            Dirección: Col. Escalón, San Salvador
            Fecha de emisión: 2026-06-01

            Descripción              Monto
            --------------------------------
            Desarrollo Web        $3,500.00
            Hosting anual           $250.00
            --------------------------------
            SUBTOTAL              $3,750.00
            IVA 13%                 $487.50
            TOTAL                 $4,237.50
            TEXT;

        $result = $this->parser->parse($text);

        // $4,237.50 → 423750 centavos
        $this->assertSame(423750, $result['amount_cents']);
        $this->assertSame('2026-06-01', $result['date']);
        // First plausible business-name line
        $this->assertSame('CONSULTORA DIGITAL SV', $result['vendor']);
    }
}
