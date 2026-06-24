<?php

declare(strict_types=1);

namespace App\Support\TaxId;

/**
 * El Salvador fiscal documents:
 *
 *  - DUI (Documento Único de Identidad): 9 digits "########-#", the last a
 *    modulo-10 check digit over the first 8 (descending weights 9..2).
 *  - NIT (Número de Identificación Tributaria): 14 digits "####-######-###-#".
 *    There is no public, agreed check-digit standard for the NIT, so it is
 *    validated by structure (length) only.
 *
 * Both are accepted because the settings field is a single "fiscal id" the tenant
 * may fill with either, depending on whether they trade as a person or a company.
 */
final class SalvadoranTaxIdStrategy implements TaxIdStrategy
{
    public function label(): string
    {
        return 'DUI / NIT';
    }

    public function placeholder(): string
    {
        return '00000000-0';
    }

    public function normalize(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    public function isValid(string $value): bool
    {
        $digits = $this->normalize($value);

        return match (strlen($digits)) {
            9 => $this->isValidDui($digits),
            14 => true, // NIT: structural length check only.
            default => false,
        };
    }

    /**
     * DUI check digit: sum the first 8 digits weighted 9,8,7,6,5,4,3,2; the
     * verifier is (10 - sum mod 10) mod 10 and must equal the 9th digit.
     */
    private function isValidDui(string $digits): bool
    {
        $sum = 0;
        for ($i = 0; $i < 8; $i++) {
            $sum += (int) $digits[$i] * (9 - $i);
        }

        $verifier = (10 - ($sum % 10)) % 10;

        return $verifier === (int) $digits[8];
    }
}
