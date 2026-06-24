<?php

declare(strict_types=1);

namespace App\Support\TaxId;

/**
 * Lenient fallback for countries without a check-digit validator wired yet
 * (CO/MX/GT/...). Accepts a reasonable alphanumeric document so we never reject a
 * legitimate id we simply don't know how to fully verify — better a permissive
 * store than a wrong rejection. The label comes from the country catalog.
 */
final class GenericTaxIdStrategy implements TaxIdStrategy
{
    public function __construct(private readonly string $label = 'ID fiscal') {}

    public function label(): string
    {
        return $this->label;
    }

    public function placeholder(): string
    {
        return '';
    }

    public function normalize(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? '';
    }

    public function isValid(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9.\-]{4,20}$/', $this->normalize($value)) === 1;
    }
}
