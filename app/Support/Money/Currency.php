<?php

declare(strict_types=1);

namespace App\Support\Money;

use InvalidArgumentException;

/**
 * Immutable value object for an ISO 4217 currency.
 *
 * Encapsulates the code (USD, COP, CLP), its minor-unit decimal count (0 for
 * COP/CLP, 2 for the rest) and a base locale used for Intl formatting. Money is
 * stored throughout the platform as an integer in the currency's minor unit
 * (centavos for USD, whole pesos for COP/CLP) — the `decimals` here is what tells
 * a formatter how many of those minor digits are fractional.
 *
 * Pure: no DB access, no framework coupling. Resolution from a tenant lives in
 * {@see Format}, so this class stays trivially unit-testable.
 */
final class Currency
{
    /**
     * Currencies whose minor unit IS the major unit (no cents). Everything else
     * is assumed to use 2 decimals — the safe ISO default for LatAm + USD.
     */
    private const ZERO_DECIMAL_CURRENCIES = ['COP', 'CLP', 'PYG', 'JPY', 'KRW', 'VND', 'ISK'];

    public function __construct(
        public readonly string $code,
        public readonly int $decimals,
        public readonly string $locale,
    ) {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException("Invalid ISO 4217 currency code: {$code}");
        }
        if ($decimals < 0 || $decimals > 4) {
            throw new InvalidArgumentException("Currency decimals must be 0-4, got: {$decimals}");
        }
    }

    /**
     * Minor-unit decimal places for a currency code (case-insensitive).
     */
    public static function decimalsFor(string $code): int
    {
        return in_array(strtoupper($code), self::ZERO_DECIMAL_CURRENCIES, true) ? 0 : 2;
    }

    public static function fromCode(string $code, string $locale = 'es-SV'): self
    {
        $code = strtoupper($code);

        return new self($code, self::decimalsFor($code), $locale);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code;
    }
}
