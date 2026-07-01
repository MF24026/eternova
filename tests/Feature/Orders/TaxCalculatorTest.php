<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Modules\Orders\Support\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase
{
    private function tax(bool $enabled, int $rateBps, bool $inclusive): array
    {
        return ['enabled' => $enabled, 'rate_bps' => $rateBps, 'prices_include_tax' => $inclusive];
    }

    public function test_disabled_yields_zero_tax(): void
    {
        $b = (new TaxCalculator())->compute(10_000, 0, $this->tax(false, 1300, false));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(0, $b->taxCents);
        $this->assertSame(10_000, $b->totalCents);
        $this->assertSame(0, $b->rateBpsApplied);
    }

    public function test_zero_rate_yields_zero_tax(): void
    {
        $b = (new TaxCalculator())->compute(10_000, 0, $this->tax(true, 0, false));
        $this->assertSame(0, $b->taxCents);
        $this->assertSame(10_000, $b->totalCents);
    }

    public function test_exclusive_adds_tax_on_top(): void
    {
        // 13% of 10_000 = 1_300; total 11_300
        $b = (new TaxCalculator())->compute(10_000, 0, $this->tax(true, 1300, false));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(1_300, $b->taxCents);
        $this->assertSame(11_300, $b->totalCents);
        $this->assertSame(1300, $b->rateBpsApplied);
    }

    public function test_inclusive_breaks_tax_out_without_changing_total(): void
    {
        // 11_300 gross incl 13%: net = round(11300*10000/11300) = 10_000; tax = 1_300
        $b = (new TaxCalculator())->compute(11_300, 0, $this->tax(true, 1300, true));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(1_300, $b->taxCents);
        $this->assertSame(11_300, $b->totalCents);
    }

    public function test_discount_clamped_and_taxed_on_net_base(): void
    {
        // exclusive: base = 10_000 - 3_000 = 7_000; tax = 910; total = 7_910
        $b = (new TaxCalculator())->compute(10_000, 3_000, $this->tax(true, 1300, false));
        $this->assertSame(10_000, $b->subtotalCents);
        $this->assertSame(3_000, $b->discountCents);
        $this->assertSame(910, $b->taxCents);
        $this->assertSame(7_910, $b->totalCents);
    }

    public function test_discount_larger_than_items_clamps_to_zero_base(): void
    {
        $b = (new TaxCalculator())->compute(5_000, 9_000, $this->tax(true, 1300, false));
        $this->assertSame(5_000, $b->discountCents); // clamped to items
        $this->assertSame(0, $b->taxCents);
        $this->assertSame(0, $b->totalCents);
    }

    public function test_invariant_subtotal_plus_tax_minus_discount_equals_total(): void
    {
        foreach ([[7_777, 0, 1300, false], [12_345, 1_111, 1300, true], [99_999, 0, 700, true]] as [$i, $d, $r, $inc]) {
            $b = (new TaxCalculator())->compute($i, $d, $this->tax(true, $r, $inc));
            $reconstructed = $inc ? ($b->subtotalCents + $b->taxCents) : ($b->subtotalCents - $b->discountCents + $b->taxCents);
            $this->assertSame($b->totalCents, $reconstructed, "case items={$i} disc={$d} bps={$r} inc=" . ($inc ? '1' : '0'));
        }
    }
}
