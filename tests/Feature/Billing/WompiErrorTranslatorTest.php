<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\GatewayError;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WompiErrorTranslatorTest extends TestCase
{
    private WompiErrorTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new WompiErrorTranslator;
    }

    /**
     * @return list<array{string, GatewayError}>
     */
    public static function knownCodes(): array
    {
        return [
            ['INSUFFICIENT_FUNDS', GatewayError::InsufficientFunds],
            ['CARD_DECLINED', GatewayError::CardDeclined],
            ['INVALID_CVC', GatewayError::InvalidCvc],
            ['EXPIRED_CARD', GatewayError::ExpiredCard],
            ['STOLEN_CARD', GatewayError::FraudRejected],
            ['NETWORK_ERROR', GatewayError::NetworkError],
        ];
    }

    #[DataProvider('knownCodes')]
    public function test_known_codes_map_to_domain_errors(string $code, GatewayError $expected): void
    {
        $this->assertSame($expected, $this->translator->translate($code));
    }

    public function test_translation_is_case_and_whitespace_insensitive(): void
    {
        $this->assertSame(GatewayError::CardDeclined, $this->translator->translate('  card_declined  '));
    }

    public function test_unknown_code_falls_back_to_unknown_error(): void
    {
        $this->assertSame(GatewayError::UnknownError, $this->translator->translate('SOMETHING_NEW'));
    }

    public function test_retryable_classification(): void
    {
        $this->assertTrue(GatewayError::NetworkError->isRetryable());
        $this->assertTrue(GatewayError::ProcessingError->isRetryable());
        $this->assertTrue(GatewayError::UnknownError->isRetryable());

        $this->assertFalse(GatewayError::CardDeclined->isRetryable());
        $this->assertFalse(GatewayError::InsufficientFunds->isRetryable());
        $this->assertFalse(GatewayError::FraudRejected->isRetryable());
    }
}
