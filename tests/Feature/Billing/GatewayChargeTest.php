<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\RecurringPlanData;
use App\Modules\Billing\Gateways\FakeGateway;
use Illuminate\Http\Client\ConnectionException;
use Tests\TestCase;

/**
 * Exercises the gateway seam through FakeGateway (the default driver in tests/dev). No DB
 * and no network — these prove the contract and the force-flag failure modes the higher
 * phases depend on.
 */
final class GatewayChargeTest extends TestCase
{
    private FakeGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $resolved = app(PaymentGatewayInterface::class);
        $this->assertInstanceOf(FakeGateway::class, $resolved, 'Default billing driver should be fake in tests.');
        $this->gateway = $resolved;
    }

    private function chargeData(int $amountCents = 2900): ChargeData
    {
        return new ChargeData(
            amountCents: $amountCents,
            currency: 'USD',
            customerEmail: 'owner@tenant.test',
            cardToken: 'tok_fake_123',
            reference: 'sub_1_period_1',
            tenantId: '01HZZZTENANT0000000000000A',
        );
    }

    public function test_charge_succeeds_and_records_the_amount(): void
    {
        $result = $this->gateway->charge($this->chargeData(2900));

        $this->assertTrue($result->isSuccess());
        $this->assertNotNull($result->transactionId);
        $this->assertSame([2900], $this->gateway->chargedAmounts);
    }

    public function test_charge_failure_is_forced_and_carries_the_error_code(): void
    {
        $this->gateway->forceChargeFailure = true;
        $this->gateway->forceErrorCode = 'insufficient_funds';

        $result = $this->gateway->charge($this->chargeData());

        $this->assertFalse($result->isSuccess());
        $this->assertSame('insufficient_funds', $result->errorCode);
        $this->assertSame([], $this->gateway->chargedAmounts, 'A failed charge must not be recorded.');
    }

    public function test_network_error_is_forced_as_an_exception(): void
    {
        $this->gateway->forceNetworkError = true;

        $this->expectException(ConnectionException::class);

        $this->gateway->charge($this->chargeData());
    }

    public function test_tokenize_returns_token_and_display_metadata(): void
    {
        $result = $this->gateway->tokenize(new CardData('4242424242424242', '123', '12', '2030'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('4242', $result->last4);
        $this->assertSame('visa', $result->brand);
        $this->assertNotNull($result->token);
    }

    public function test_tokenize_failure_is_forced(): void
    {
        $this->gateway->forceTokenizeFailure = true;

        $result = $this->gateway->tokenize(new CardData('4000000000000002', '123', '12', '2030'));

        $this->assertFalse($result->isSuccess());
    }

    public function test_refund_records_the_refund(): void
    {
        $result = $this->gateway->refund('fake_tx_1', 2900, 'idem-key-1');

        $this->assertTrue($result->isSuccess());
        $this->assertSame([['transactionId' => 'fake_tx_1', 'amountCents' => 2900]], $this->gateway->refundsIssued);
    }

    public function test_recurring_link_is_created_and_recorded(): void
    {
        $link = $this->gateway->createRecurringPaymentLink(new RecurringPlanData(2900, 10, 'Pro', 'Mensual'));

        $this->assertTrue($link->isSuccess());
        $this->assertNotNull($link->shortUrl);
        $this->assertNotNull($link->linkId);
        $this->assertSame([['amountCents' => 2900, 'dayOfMonth' => 10]], $this->gateway->recurringLinks);
    }

    public function test_recurring_link_failure_is_forced(): void
    {
        $this->gateway->forceRecurringLinkFailure = true;

        $this->assertFalse(
            $this->gateway->createRecurringPaymentLink(new RecurringPlanData(2900, 10, 'Pro', 'Mensual'))->isSuccess()
        );
    }

    public function test_webhook_signature_verification(): void
    {
        $payload = '{"event":"transaction.updated"}';
        $signature = $this->gateway->signWebhook($payload);

        $this->assertTrue($this->gateway->verifyWebhookSignature($payload, $signature));
        $this->assertFalse($this->gateway->verifyWebhookSignature($payload, 'wrong-signature'));
    }

    public function test_forced_invalid_webhook_signature(): void
    {
        $this->gateway->forceWebhookSignatureInvalid = true;
        $payload = '{"event":"x"}';

        $this->assertFalse(
            $this->gateway->verifyWebhookSignature($payload, $this->gateway->signWebhook($payload))
        );
    }
}
