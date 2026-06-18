<?php

declare(strict_types=1);

namespace App\Modules\Billing\Enums;

/**
 * App-domain payment error vocabulary. Provider-specific codes (Wompi, Stripe, ...) are
 * translated into these by the per-provider ErrorTranslator, so the rest of the billing
 * code never branches on a vendor's raw error strings.
 *
 * isRetryable() decides whether dunning should bother retrying: a declined card won't
 * succeed on retry, but a transient network blip might.
 */
enum GatewayError: string
{
    case InsufficientFunds = 'insufficient_funds';
    case CardDeclined = 'card_declined';
    case InvalidCvc = 'invalid_cvc';
    case ExpiredCard = 'expired_card';
    case FraudRejected = 'fraud_rejected';
    case ProcessingError = 'processing_error';
    case NetworkError = 'network_error';
    case UnknownError = 'unknown_error';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::NetworkError, self::ProcessingError, self::UnknownError => true,
            default => false,
        };
    }

    /**
     * Safe, generic message for surfacing to the tenant. Never includes raw gateway text.
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::InsufficientFunds => 'La tarjeta no tiene fondos suficientes.',
            self::CardDeclined => 'La tarjeta fue rechazada.',
            self::InvalidCvc => 'El codigo de seguridad (CVC) es invalido.',
            self::ExpiredCard => 'La tarjeta esta vencida.',
            self::FraudRejected => 'El pago fue rechazado por seguridad.',
            self::ProcessingError, self::NetworkError, self::UnknownError => 'No pudimos procesar el pago. Intenta de nuevo.',
        };
    }
}
