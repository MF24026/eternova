<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

/** Outcome of a refund attempt. */
final readonly class RefundResult
{
    private function __construct(
        public bool $success,
        public ?string $refundId,
        public ?string $errorCode,
        public ?string $errorMessage,
    ) {}

    public static function succeeded(string $refundId): self
    {
        return new self(true, $refundId, null, null);
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
