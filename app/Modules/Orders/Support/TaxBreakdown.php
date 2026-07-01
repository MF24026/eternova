<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * Immutable result of a tax computation. All values are integer cents except
 * rateBpsApplied (basis points). Invariant: subtotalCents + taxCents == totalCents
 * (inclusive) or subtotalCents - discountCents + taxCents == totalCents (exclusive).
 *
 * subtotalCents is mode-dependent: in exclusive mode it is the pre-discount items
 * total; in inclusive mode it is the tax-exclusive net (discount applied, tax
 * extracted). Callers rendering a "subtotal" line must account for this.
 */
final readonly class TaxBreakdown
{
    public function __construct(
        public int $subtotalCents,
        public int $taxCents,
        public int $discountCents,
        public int $totalCents,
        public int $rateBpsApplied,
    ) {}
}
