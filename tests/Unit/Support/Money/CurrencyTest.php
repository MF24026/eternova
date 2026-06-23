<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Money;

use App\Support\Money\Currency;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CurrencyTest extends TestCase
{
    public function test_zero_decimal_currencies_resolve_to_zero(): void
    {
        $this->assertSame(0, Currency::decimalsFor('COP'));
        $this->assertSame(0, Currency::decimalsFor('CLP'));
    }

    #[DataProvider('twoDecimalCurrencies')]
    public function test_other_currencies_default_to_two_decimals(string $code): void
    {
        $this->assertSame(2, Currency::decimalsFor($code));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function twoDecimalCurrencies(): array
    {
        return [
            'USD' => ['USD'],
            'MXN' => ['MXN'],
            'GTQ' => ['GTQ'],
            'CRC' => ['CRC'],
            'PEN' => ['PEN'],
            'ARS' => ['ARS'],
        ];
    }

    public function test_decimals_lookup_is_case_insensitive(): void
    {
        $this->assertSame(0, Currency::decimalsFor('cop'));
        $this->assertSame(2, Currency::decimalsFor('usd'));
    }

    public function test_from_code_builds_value_object_with_resolved_decimals(): void
    {
        $cop = Currency::fromCode('cop', 'es-CO');

        $this->assertSame('COP', $cop->code);
        $this->assertSame(0, $cop->decimals);
        $this->assertSame('es-CO', $cop->locale);
    }

    public function test_invalid_iso_code_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Currency('US', 2, 'es-SV');
    }

    public function test_out_of_range_decimals_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Currency('USD', 5, 'es-SV');
    }

    public function test_equals_compares_by_code(): void
    {
        $this->assertTrue(Currency::fromCode('USD')->equals(Currency::fromCode('USD', 'en-US')));
        $this->assertFalse(Currency::fromCode('USD')->equals(Currency::fromCode('COP')));
    }
}
