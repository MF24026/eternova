<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * Pure per-tenant tax computation over integer cents. Mirrors QuotationService's
 * exclusive formula and adds an inclusive mode. Rounding derives one part from the
 * other so the breakdown always reconciles to the total.
 *
 * @phpstan-param array{enabled?: bool, rate_bps?: int, prices_include_tax?: bool} $tax
 */
final class TaxCalculator
{
    public function compute(int $itemsCents, int $discountCents, array $tax): TaxBreakdown
    {
        $enabled   = (bool) ($tax['enabled'] ?? false);
        $rateBps   = (int) ($tax['rate_bps'] ?? 0);
        $inclusive = (bool) ($tax['prices_include_tax'] ?? false);

        $discount = min(max(0, $discountCents), max(0, $itemsCents));

        if (! $enabled || $rateBps <= 0) {
            return new TaxBreakdown($itemsCents, 0, $discount, max(0, $itemsCents - $discount), 0);
        }

        $base = $itemsCents - $discount; // >= 0 by the clamp above

        if ($inclusive) {
            // net = round-half-up(base * 10000 / (10000 + bps)); tax = base - net.
            $divisor  = 10_000 + $rateBps;
            $net      = intdiv($base * 10_000 + intdiv($divisor, 2), $divisor);
            $taxCents = $base - $net;

            return new TaxBreakdown($net, $taxCents, $discount, $base, $rateBps);
        }

        // Exclusive: floor tax, add on top (matches QuotationService::calculateTotals).
        $taxCents = intdiv($base * $rateBps, 10_000);

        return new TaxBreakdown($itemsCents, $taxCents, $discount, $base + $taxCents, $rateBps);
    }
}
