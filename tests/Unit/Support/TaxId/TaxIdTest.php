<?php

declare(strict_types=1);

namespace Tests\Unit\Support\TaxId;

use App\Support\TaxId\GenericTaxIdStrategy;
use App\Support\TaxId\SalvadoranTaxIdStrategy;
use App\Support\TaxId\TaxIdStrategyFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase; // boots config() so the factory can read the country catalog

final class TaxIdTest extends TestCase
{
    // DUI 04210323-4: weighted sum 76, verifier (10 - 76 % 10) % 10 = 4.
    private const VALID_DUI = '04210323-4';

    public function test_salvadoran_accepts_a_valid_dui_with_check_digit(): void
    {
        $sv = new SalvadoranTaxIdStrategy();

        $this->assertTrue($sv->isValid(self::VALID_DUI));
        $this->assertTrue($sv->isValid('042103234')); // dashes are optional
    }

    public function test_salvadoran_rejects_a_dui_with_wrong_check_digit(): void
    {
        $sv = new SalvadoranTaxIdStrategy();

        $this->assertFalse($sv->isValid('04210323-5'));
    }

    public function test_salvadoran_accepts_a_14_digit_nit_by_structure(): void
    {
        $sv = new SalvadoranTaxIdStrategy();

        $this->assertTrue($sv->isValid('0614-280128-102-3'));
    }

    #[DataProvider('malformedSalvadoranIds')]
    public function test_salvadoran_rejects_malformed_ids(string $value): void
    {
        $this->assertFalse((new SalvadoranTaxIdStrategy())->isValid($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedSalvadoranIds(): array
    {
        return [
            'too short' => ['123'],
            'eleven digits' => ['01234567890'],
            'empty' => [''],
            'letters' => ['ABCDEFGH-1'],
        ];
    }

    public function test_generic_is_lenient_but_rejects_garbage(): void
    {
        $generic = new GenericTaxIdStrategy('NIT');

        $this->assertSame('NIT', $generic->label());
        $this->assertTrue($generic->isValid('900.123.456-7'));
        $this->assertFalse($generic->isValid('!!'));
        $this->assertFalse($generic->isValid('x')); // below min length
    }

    public function test_factory_returns_salvadoran_for_sv(): void
    {
        $this->assertInstanceOf(SalvadoranTaxIdStrategy::class, TaxIdStrategyFactory::for('SV'));
        $this->assertInstanceOf(SalvadoranTaxIdStrategy::class, TaxIdStrategyFactory::for('sv'));
    }

    public function test_factory_falls_back_to_generic_for_other_countries(): void
    {
        $co = TaxIdStrategyFactory::for('CO');

        $this->assertInstanceOf(GenericTaxIdStrategy::class, $co);
        // Label is pulled from the country catalog (CO -> NIT).
        $this->assertSame('NIT', $co->label());
    }
}
