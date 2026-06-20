<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

/**
 * Input for creating a Wompi-managed recurring payment link (a subscription the tenant affiliates
 * once on Wompi's hosted page; Wompi then charges automatically each month on dayOfMonth).
 */
final readonly class RecurringPlanData
{
    public function __construct(
        public int $amountCents,
        public int $dayOfMonth,      // diaDePago — day of the month Wompi charges
        public string $name,         // nombre — shown to the customer
        public string $description,  // descripcionProducto
    ) {}
}
