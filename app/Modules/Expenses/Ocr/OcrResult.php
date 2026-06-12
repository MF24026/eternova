<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Ocr;

/**
 * Immutable value object returned by every OCR driver.
 *
 * All fields are suggestions — the user must confirm/correct before an
 * Expense is marked is_verified=true. Never treat these as authoritative.
 *
 * money: amountCents is in centavos (integer × 100). null means the
 * driver could not extract a monetary value with any confidence.
 *
 * date: ISO 8601 date string (yyyy-mm-dd) or null when parsing failed.
 *
 * lineItems: optional structured lines extracted from the receipt body.
 * Each item is a plain array — shape is intentionally loose because
 * different receipt layouts expose different fields. E3/E4 will define
 * a stricter shape if line-item import is built.
 *
 * confidence: driver-supplied overall confidence in [0.0, 1.0], or null
 * when the driver does not produce a confidence score (e.g. FakeOcrDriver).
 *
 * @phpstan-type LineItem array<string, mixed>
 */
final readonly class OcrResult
{
    /**
     * @param list<array<string, mixed>> $lineItems
     */
    public function __construct(
        public readonly string  $rawText,
        public readonly ?string $vendor,
        public readonly ?int    $amountCents,
        public readonly ?string $date,
        public readonly ?float  $confidence,
        public readonly array   $lineItems = [],
    ) {}

    /**
     * Serialise to the shape stored in expenses.ocr_data (JSON column).
     *
     * Keys match the sprint-6 ERD spec verbatim so E3/E4 can read
     * ocr_data without any transformation.
     *
     * @return array{
     *     vendor: string|null,
     *     amount_cents: int|null,
     *     date: string|null,
     *     raw_text: string,
     *     confidence: float|null,
     *     line_items: list<array<string, mixed>>,
     * }
     */
    public function toArray(): array
    {
        return [
            'vendor'       => $this->vendor,
            'amount_cents' => $this->amountCents,
            'date'         => $this->date,
            'raw_text'     => $this->rawText,
            'confidence'   => $this->confidence,
            'line_items'   => $this->lineItems,
        ];
    }
}
