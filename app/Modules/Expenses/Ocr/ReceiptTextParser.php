<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Ocr;

/**
 * Heuristic parser that extracts vendor, monetary total, and date from raw
 * receipt text produced by Tesseract (or any OCR engine).
 *
 * Design principles:
 *   - Pure PHP, no external dependencies, no I/O. Trivially unit-testable.
 *   - Returns all-null on garbage / empty input. Never throws.
 *   - Heuristics are ordered by confidence: explicit TOTAL label first,
 *     then fallback to the largest number in the text.
 *   - "Assistive" only — all output is a suggestion for the user to review.
 *
 * LatAm receipt conventions handled:
 *   - Amounts: decimal separator may be '.' or ',' (ES locale uses comma).
 *     Thousands separator is the other symbol. We resolve ambiguity by
 *     treating the rightmost separator as the decimal one when exactly 1-2
 *     digits follow it.
 *   - Currency prefixes: $, Q (Guatemala), C$ (Nicaragua), S/ (Perú), etc.
 *   - Date formats: dd/mm/yyyy, dd-mm-yyyy, yyyy-mm-dd, dd/mm/yy.
 *   - Vendor: typically the first non-empty non-numeric line at the top
 *     of the receipt, or the line adjacent to a tax-id marker.
 */
final class ReceiptTextParser
{
    /**
     * Parse raw OCR text into structured receipt fields.
     *
     * @return array{vendor: string|null, amount_cents: int|null, date: string|null}
     */
    public function parse(string $rawText): array
    {
        $text = trim($rawText);

        if ($text === '') {
            return ['vendor' => null, 'amount_cents' => null, 'date' => null];
        }

        $lines = $this->splitLines($text);

        return [
            'vendor' => $this->extractVendor($lines),
            'amount_cents' => $this->extractAmountCents($lines),
            'date' => $this->extractDate($lines),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Amount extraction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Find the receipt TOTAL and convert to centavos.
     *
     * Strategy:
     *   1. Collect all lines that contain the word TOTAL but NOT SUBTOTAL.
     *      From those lines extract all monetary-looking values and take the
     *      largest. "Largest" is correct because TOTAL GENERAL > TOTAL PARCIAL.
     *   2. If no TOTAL line found, fall back to the largest monetary value in
     *      the entire text (catches receipts that print only a bare amount).
     */
    private function extractAmountCents(array $lines): ?int
    {
        // Step 1: search TOTAL lines (case-insensitive, exclude SUBTOTAL).
        $totalLines = array_filter(
            $lines,
            static fn (string $line): bool => stripos($line, 'TOTAL') !== false &&
                stripos($line, 'SUBTOTAL') === false,
        );

        if ($totalLines !== []) {
            $candidates = [];
            foreach ($totalLines as $line) {
                foreach ($this->extractMonetaryValues($line) as $value) {
                    $candidates[] = $value;
                }
            }
            if ($candidates !== []) {
                return max($candidates);
            }
        }

        // Step 2: fallback — largest monetary value in the full text, but
        // only from lines that look like they carry a price (contain a
        // currency-adjacent number, not purely textual lines).
        $allValues = [];
        foreach ($lines as $line) {
            foreach ($this->extractMonetaryValues($line) as $value) {
                $allValues[] = $value;
            }
        }

        return $allValues !== [] ? max($allValues) : null;
    }

    /**
     * Extract all plausible monetary values from a single line, in centavos.
     *
     * A "monetary value" on a receipt line is a number that:
     *   - Is optionally preceded by a recognised currency symbol/prefix.
     *   - OR follows typical receipt formatting (right-aligned, after spaces).
     *
     * We exclude plain integers that look like dates, document numbers,
     * tax IDs, or quantities (no decimal part and fewer than 4 digits when
     * no currency symbol is present).
     *
     * Handles:
     *   - Leading currency symbols: $, Q, C$, S/, L, ₡ (colón), B/. (balboa)
     *   - Thousands separators: 1.234,56  |  1,234.56  |  1234.56  |  1234,56
     *   - Decimal-less amounts: $500 → 50000 centavos (only when prefixed)
     *
     * @return list<int> Values in centavos (integer).
     */
    private function extractMonetaryValues(string $line): array
    {
        // Currency symbol prefixes we recognise.
        $currencyPattern = '(?:\$|Q|C\$|S\/|₡|B\/\.)\s*';

        // Full pattern: optional currency prefix + number with optional decimal.
        // The number part: digits with optional thousands separators, then optional
        // decimal part of exactly 1-2 digits after the last separator.
        $numberPattern = '\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?|\d+[.,]\d{1,2}';

        // Match currency-prefixed amounts (no minimum digit requirement).
        $results = [];

        // Pattern A: currency symbol present — capture the full number.
        $patternA = '/(?:'.$currencyPattern.')('.$numberPattern.')\b/u';
        if (preg_match_all($patternA, $line, $matches)) {
            foreach ($matches[1] as $raw) {
                $value = $this->parseCurrencyString($raw);
                if ($value !== null && $value > 0) {
                    $results[] = $value;
                }
            }
        }

        // Pattern B: no currency symbol — only parse numbers that have an
        // explicit decimal part (e.g. "36.25" or "36,25"). Pure integers without
        // a currency prefix are too ambiguous (could be quantity, year, doc number).
        $patternB = '/\b(\d{1,3}(?:[.,]\d{3})*[.,]\d{1,2}|\d+[.,]\d{1,2})\b/';
        if (preg_match_all($patternB, $line, $matches)) {
            foreach ($matches[1] as $raw) {
                // Skip if this looks like a date component (dd/mm, mm/yyyy etc.).
                if (preg_match('/\d{1,2}[\/\-]\d{1,2}/', $raw)) {
                    continue;
                }
                $value = $this->parseCurrencyString($raw);
                if ($value !== null && $value > 0) {
                    $results[] = $value;
                }
            }
        }

        return $results;
    }

    /**
     * Convert a raw currency string to centavos.
     *
     * Decimal-separator detection rule:
     *   The rightmost separator (. or ,) is the decimal separator when
     *   it is followed by exactly 1 or 2 digits. All other separators
     *   before it are thousands separators and are stripped.
     *
     * Examples:
     *   "1.234,50" → 123450  (ES: dot=thousands, comma=decimal)
     *   "1,234.50" → 123450  (EN: comma=thousands, dot=decimal)
     *   "1234.5"   → 123450  (single decimal digit, padded)
     *   "1234"     → 123400  (whole number × 100, only from currency-prefixed paths)
     *   "40.96"    → 4096    (plain decimal)
     */
    private function parseCurrencyString(string $raw): ?int
    {
        // Strip leading/trailing whitespace.
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        // If the raw string ends with [.,](\d{1,2}) treat that as the decimal.
        if (preg_match('/^([\d.,]*\d)[.,](\d{1,2})$/', $raw, $m)) {
            // Integer part: strip ALL separators (both . and ,).
            $integerPart = preg_replace('/[.,]/', '', $m[1]) ?? '';
            // Decimal part: right-pad to 2 digits so "5" → "50" centavos.
            $decimalPart = str_pad($m[2], 2, '0', STR_PAD_RIGHT);

            if (! ctype_digit($integerPart) || ! ctype_digit($decimalPart)) {
                return null;
            }

            return (int) $integerPart * 100 + (int) $decimalPart;
        }

        // No decimal separator — treat as whole units (× 100).
        // Strip all separators.
        $digits = preg_replace('/[.,]/', '', $raw) ?? '';

        if ($digits === '' || ! ctype_digit($digits)) {
            return null;
        }

        return (int) $digits * 100;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Date extraction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Find the first plausible date in the text and normalise to ISO yyyy-mm-dd.
     *
     * Supported input formats:
     *   - dd/mm/yyyy  (most common LatAm printed receipt format)
     *   - dd-mm-yyyy
     *   - yyyy-mm-dd  (ISO, databases, some digital receipts)
     *   - dd/mm/yy    (2-digit year → assume 2000+)
     *
     * Validation: month must be 1-12, day 1-31.
     */
    private function extractDate(array $lines): ?string
    {
        foreach ($lines as $line) {
            $date = $this->matchDate($line);
            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    private function matchDate(string $line): ?string
    {
        // yyyy-mm-dd (ISO) — must check this BEFORE dd/mm/yyyy because the
        // 4-digit group at the start is unambiguous as a year.
        if (preg_match('/\b(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})\b/', $line, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($year >= 1900 && $this->isValidDayMonth($day, $month)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        // dd/mm/yyyy or dd-mm-yyyy (4-digit year)
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $line, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($year >= 1900 && $this->isValidDayMonth($day, $month)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        // dd/mm/yy — 2-digit year (2000+ assumed)
        if (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2})\b/', $line, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3] + 2000];
            if ($this->isValidDayMonth($day, $month)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        return null;
    }

    private function isValidDayMonth(int $day, int $month): bool
    {
        return $day >= 1 && $day <= 31
            && $month >= 1 && $month <= 12;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Vendor extraction
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Guess the vendor/business name from the receipt text.
     *
     * Heuristics applied in order:
     *   1. Line immediately before or after a tax-id marker (RUC, NIT, RFC,
     *      NRC — LatAm equivalents of a VAT number).
     *   2. The first non-empty line that looks like a business name (not
     *      purely numeric, not a date, not a currency amount, min 4 chars).
     *
     * Returns null when neither heuristic fires.
     */
    private function extractVendor(array $lines): ?string
    {
        // Heuristic 1: tax-id proximity.
        $vendor = $this->vendorFromTaxIdProximity($lines);
        if ($vendor !== null) {
            return $vendor;
        }

        // Heuristic 2: first plausible top-of-receipt line.
        foreach (array_slice($lines, 0, 8) as $line) {
            $trimmed = trim($line);
            if ($this->looksLikeBusinessName($trimmed)) {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * Look for lines adjacent to LatAm tax-id markers and return the
     * non-id line as the business name.
     *
     * Markers: RUC (Panamá/Ecuador/Perú), NIT (Colombia/Guatemala/El Salvador),
     * RFC (México), NRC (El Salvador), CUIT (Argentina), RIF (Venezuela).
     */
    private function vendorFromTaxIdProximity(array $lines): ?string
    {
        $taxIdPattern = '/\b(?:RUC|NIT|RFC|NRC|CUIT|RIF)\b/i';

        foreach ($lines as $index => $line) {
            if (! preg_match($taxIdPattern, $line)) {
                continue;
            }

            // Check the line above and below the tax-id line.
            foreach ([$index - 1, $index + 1] as $adjacent) {
                if (! isset($lines[$adjacent])) {
                    continue;
                }
                $candidate = trim($lines[$adjacent]);
                if ($this->looksLikeBusinessName($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * True when a line plausibly is a business name rather than a data field.
     *
     * Rejects: empty strings, pure numbers, date-shaped strings, lines that
     * ARE a label keyword with a trailing amount (e.g. "TOTAL $5.00"), and
     * very short strings (likely labels like "No.", "Tel:").
     */
    private function looksLikeBusinessName(string $line): bool
    {
        if (strlen($line) < 4) {
            return false;
        }

        // Reject pure-numeric lines (invoice/document numbers, amounts).
        if (preg_match('/^\d[\d.,\s]*$/', $line)) {
            return false;
        }

        // Reject lines that are a date pattern.
        if ($this->matchDate($line) !== null) {
            return false;
        }

        // Reject receipt keyword lines (TOTAL, SUBTOTAL, IVA, etc.) that act
        // as labels, not business names. These lines start with a keyword that
        // wouldn't appear in a company name.
        if (preg_match('/^\s*(?:TOTAL|SUBTOTAL|IVA|TAX|IMPUESTO|DESCUENTO|DISCOUNT|GRACIAS|THANK)\b/i', $line)) {
            return false;
        }

        // Must contain at least one letter.
        if (! preg_match('/[a-záéíóúüñA-ZÁÉÍÓÚÜÑ]/u', $line)) {
            return false;
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Utility
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Split raw OCR text into non-empty lines.
     *
     * @return list<string>
     */
    private function splitLines(string $text): array
    {
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $normalised);

        return array_values(array_filter($lines, static fn (string $l): bool => trim($l) !== ''));
    }
}
