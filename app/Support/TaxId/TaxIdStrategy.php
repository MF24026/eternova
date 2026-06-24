<?php

declare(strict_types=1);

namespace App\Support\TaxId;

/**
 * Per-country strategy for a tenant's fiscal identity document (DUI/NIT in SV,
 * NIT in CO, RFC in MX, ...). Encapsulates the human label and the validation a
 * given country requires, so the rest of the app never branches on country code.
 */
interface TaxIdStrategy
{
    /** Human label for the field, e.g. "DUI / NIT", "RFC". */
    public function label(): string;

    /** Example value for the input placeholder. */
    public function placeholder(): string;

    /** Strip formatting to a canonical comparable form. */
    public function normalize(string $value): string;

    /** Whether the value is a structurally valid document for this country. */
    public function isValid(string $value): bool;
}
