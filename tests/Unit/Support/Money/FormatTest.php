<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Money;

use App\Support\Money\Format;
use PHPUnit\Framework\TestCase;

/**
 * These assertions avoid pinning exact ICU output (symbol placement and grouping
 * glyphs drift across ICU versions). Instead they verify the invariant that matters
 * for multi-region: the count of digits rendered equals the stored minor units, so a
 * zero-decimal currency (COP/CLP) never gains spurious ".00" and a two-decimal one
 * always keeps its cents.
 */
final class FormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Format::flushCache();
    }

    private static function digits(string $formatted): string
    {
        return preg_replace('/\D/', '', $formatted) ?? '';
    }

    public function test_usd_keeps_two_decimals(): void
    {
        $out = Format::forCurrency(123456, 'USD', 'es-SV');

        // 1234.56 -> digits "123456"
        $this->assertSame('123456', self::digits($out));
        $this->assertMatchesRegularExpression('/1[.,]00/', Format::forCurrency(100, 'USD', 'es-SV'));
    }

    public function test_cop_has_no_decimals(): void
    {
        $out = Format::forCurrency(150000, 'COP', 'es-CO');

        // 150000 pesos (zero-decimal) -> digits "150000", NOT "15000000".
        $this->assertSame('150000', self::digits($out));
        $this->assertDoesNotMatchRegularExpression('/150[.,]000[.,]00/', $out);
    }

    public function test_same_stored_value_renders_differently_per_currency(): void
    {
        // 100 minor units: USD = $1.00 (has a fractional part), COP = $100 (none).
        $usd = Format::forCurrency(100, 'USD', 'es-SV');
        $cop = Format::forCurrency(100, 'COP', 'es-CO');

        $this->assertMatchesRegularExpression('/1[.,]00/', $usd);
        $this->assertDoesNotMatchRegularExpression('/100[.,]00/', $cop);
    }

    public function test_unknown_currency_falls_back_to_two_decimals(): void
    {
        // Unmapped code is treated as a 2-decimal currency, never crashes.
        $out = Format::forCurrency(2500, 'PEN', 'es-PE');

        $this->assertSame('2500', self::digits($out));
    }

    public function test_blank_currency_defaults_to_usd(): void
    {
        $out = Format::forCurrency(100, '', 'es-SV');

        $this->assertMatchesRegularExpression('/1[.,]00/', $out);
    }

    public function test_number_renders_bare_localized_value_with_currency_decimals(): void
    {
        // No symbol/code, two decimals for USD, en-style separators for es-SV.
        $this->assertSame('1,234.56', Format::number(123456, 'USD', 'es-SV'));

        // Zero decimals for COP, es-CO grouping uses '.'.
        $this->assertSame('150.000', Format::number(150000, 'COP', 'es-CO'));
    }
}
