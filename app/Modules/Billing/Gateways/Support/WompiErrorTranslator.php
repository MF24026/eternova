<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways\Support;

use App\Modules\Billing\Enums\GatewayError;

/**
 * Maps Wompi's raw decline/error reasons onto the app-domain GatewayError vocabulary.
 * Anything unmapped falls back to UnknownError (retryable), so a new provider code degrades
 * to "retry later" rather than silently being treated as a permanent decline.
 */
final class WompiErrorTranslator
{
    /** @var array<string, GatewayError> */
    private const MAP = [
        'INSUFFICIENT_FUNDS' => GatewayError::InsufficientFunds,
        'INSUFFICIENT_BALANCE' => GatewayError::InsufficientFunds,
        'CARD_DECLINED' => GatewayError::CardDeclined,
        'DECLINED' => GatewayError::CardDeclined,
        'DO_NOT_HONOR' => GatewayError::CardDeclined,
        'INVALID_CVC' => GatewayError::InvalidCvc,
        'INVALID_CVV' => GatewayError::InvalidCvc,
        'INCORRECT_CVC' => GatewayError::InvalidCvc,
        'EXPIRED_CARD' => GatewayError::ExpiredCard,
        'FRAUD_REJECTED' => GatewayError::FraudRejected,
        'FRAUD' => GatewayError::FraudRejected,
        'STOLEN_CARD' => GatewayError::FraudRejected,
        'LOST_CARD' => GatewayError::FraudRejected,
        'RESTRICTED_CARD' => GatewayError::FraudRejected,
        'PROCESSING_ERROR' => GatewayError::ProcessingError,
        'ISSUER_ERROR' => GatewayError::ProcessingError,
        'NETWORK_ERROR' => GatewayError::NetworkError,
        'TIMEOUT' => GatewayError::NetworkError,
    ];

    public function translate(string $providerCode): GatewayError
    {
        return self::MAP[strtoupper(trim($providerCode))] ?? GatewayError::UnknownError;
    }
}
