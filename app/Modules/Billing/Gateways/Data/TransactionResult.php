<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

/**
 * A transaction as the gateway currently sees it, used by the reconciliation cron to detect
 * drift between our DB and the provider. `status` is the raw provider status string
 * (e.g. APPROVED/DECLINED/VOIDED) — reconciliation maps it, it is not pre-translated.
 */
final readonly class TransactionResult
{
    public function __construct(
        public string $id,
        public string $status,
        public ?int $amountCents = null,
        public ?string $currency = null,
    ) {}
}
