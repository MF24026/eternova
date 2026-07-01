<?php

declare(strict_types=1);

namespace App\Modules\Orders\Support;

/**
 * Immutable result of a tax computation. All values are integer cents except
 * rateBpsApplied (basis points). Invariant: subtotalCents + taxCents == totalCents
 * (inclusive) or subtotalCents - discountCents + taxCents == totalCents (exclusive).
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
