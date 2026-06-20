<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Data;

/**
 * Result of creating a recurring payment link. `linkId` (Wompi's idEnlace) is stored as the
 * subscription's gateway_subscription_id and is what webhooks reference. `shortUrl` is sent to
 * the tenant to affiliate their card (replaces a self-hosted card iframe).
 */
final readonly class RecurringPaymentLink
{
    public function __construct(
        public bool $success,
        public ?string $linkId,
        public ?string $shortUrl,
        public ?string $longUrl,
        public ?string $qrUrl,
        public bool $isProductive = false,
        public ?string $errorCode = null,
    ) {}

    public static function succeeded(string $linkId, string $shortUrl, ?string $longUrl, ?string $qrUrl, bool $isProductive): self
    {
        return new self(true, $linkId, $shortUrl, $longUrl, $qrUrl, $isProductive);
    }

    public static function failed(string $code): self
    {
        return new self(false, null, null, null, null, false, $code);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }
}
