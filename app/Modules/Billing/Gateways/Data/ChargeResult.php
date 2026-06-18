<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

/**
 * Outcome of a charge attempt. Success carries the gateway transaction id; failure carries
 * a translated, app-domain error code (see GatewayError) and a safe, human message — never
 * the raw gateway response.
 */
final readonly class ChargeResult
{
    private function __construct(
        public bool $success,
        public ?string $transactionId,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    public static function succeeded(string $transactionId): self
    {
        return new self(true, $transactionId, null, null);
    }

    public static function failed(string $code, string $message): self
    {
        return new self(false, null, $code, $message);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}
