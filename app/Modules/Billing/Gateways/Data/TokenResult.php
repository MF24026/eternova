<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

use SensitiveParameter;

/**
 * Outcome of tokenizing a card. On success it carries the opaque token plus the display
 * metadata (last4/brand/expiry) we are allowed to store. __debugInfo() masks the token.
 */
final readonly class TokenResult
{
    private function __construct(
        public bool $success,
        #[SensitiveParameter] public ?string $token,
        public ?string $last4,
        public ?string $brand,
        public ?int $expMonth,
        public ?int $expYear,
        public ?string $errorCode,
    ) {}

    public static function succeeded(
        string $token,
        string $last4,
        string $brand,
        int $expMonth,
        int $expYear,
    ): self {
        return new self(true, $token, $last4, $brand, $expMonth, $expYear, null);
    }

    public static function failed(string $code): self
    {
        return new self(false, null, null, null, null, null, $code);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'success' => $this->success,
            'token' => $this->token === null ? null : '***redacted***',
            'last4' => $this->last4,
            'brand' => $this->brand,
            'expMonth' => $this->expMonth,
            'expYear' => $this->expYear,
            'errorCode' => $this->errorCode,
        ];
    }
}
