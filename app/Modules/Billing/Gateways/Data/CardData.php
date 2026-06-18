<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

use SensitiveParameter;

/**
 * Raw card input, used ONLY at the tokenization boundary. The PAN and CVV must never be
 * persisted, logged, or serialized — they exist in memory just long enough to hand to the
 * gateway's tokenization endpoint, which returns an opaque token we keep instead.
 *
 * __debugInfo() masks every field so a var_dump / dd / stack-trace render of this object
 * can never spill a PAN. Combined with #[\SensitiveParameter] on the methods that receive
 * it, the card number stays out of error reporting.
 */
final readonly class CardData
{
    public function __construct(
        #[SensitiveParameter] public string $number,
        #[SensitiveParameter] public string $cvv,
        public string $expMonth,
        public string $expYear,
        public ?string $holderName = null,
    ) {}

    public function last4(): string
    {
        return substr($this->number, -4);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return [
            'number' => '****'.$this->last4(),
            'cvv' => '***',
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'holderName' => $this->holderName ?? '',
        ];
    }
}
