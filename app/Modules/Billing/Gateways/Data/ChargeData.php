<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

use SensitiveParameter;

/**
 * Everything needed to attempt one charge. cardToken is the gateway token (never a PAN),
 * but it is still a bearer credential, so __debugInfo() masks it: a leaked token can be
 * replayed against the gateway.
 */
final readonly class ChargeData
{
    public function __construct(
        public int $amountCents,
        public string $currency,
        public string $customerEmail,
        #[SensitiveParameter] public string $cardToken,
        public string $reference,
        public ?string $tenantId = null,
        public ?string $customerName = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'amountCents' => $this->amountCents,
            'currency' => $this->currency,
            'customerEmail' => $this->customerEmail,
            'cardToken' => '***redacted***',
            'reference' => $this->reference,
            'tenantId' => $this->tenantId,
            'customerName' => $this->customerName,
        ];
    }
}
