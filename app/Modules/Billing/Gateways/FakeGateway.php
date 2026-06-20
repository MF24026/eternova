<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways;

use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\ChargeResult;
use App\Modules\Billing\Gateways\Data\RecurringPaymentLink;
use App\Modules\Billing\Gateways\Data\RecurringPlanData;
use App\Modules\Billing\Gateways\Data\RefundResult;
use App\Modules\Billing\Gateways\Data\TokenResult;
use App\Modules\Billing\Gateways\Data\TransactionResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * In-memory gateway for tests and local dev. Force flags let a test drive any failure mode
 * deterministically without touching the network; the recorded arrays let tests assert what
 * was charged/refunded. Phases 1-5 and 7 are built entirely against this — real Wompi keys
 * are only needed for the Phase 2 sandbox smoke and the Phase 6 payment iframe.
 */
final class FakeGateway implements PaymentGatewayInterface
{
    public bool $forceChargeFailure = false;

    public bool $forceNetworkError = false;

    public ?string $forceErrorCode = null;

    public bool $forceTokenizeFailure = false;

    public bool $forceWebhookSignatureInvalid = false;

    public bool $forceRecurringLinkFailure = false;

    /** @var list<array{amountCents: int, dayOfMonth: int}> */
    public array $recurringLinks = [];

    public string $webhookSecret = 'fake-events-secret';

    public string $transactionStatus = 'APPROVED';

    /** @var list<int> */
    public array $chargedAmounts = [];

    /** @var list<array{transactionId: string, amountCents: int}> */
    public array $refundsIssued = [];

    public function createRecurringPaymentLink(RecurringPlanData $data): RecurringPaymentLink
    {
        if ($this->forceRecurringLinkFailure) {
            return RecurringPaymentLink::failed('declined');
        }

        $this->recurringLinks[] = ['amountCents' => $data->amountCents, 'dayOfMonth' => $data->dayOfMonth];
        $id = 'fake_link_'.Str::uuid()->toString();

        return RecurringPaymentLink::succeeded(
            linkId: $id,
            shortUrl: "https://fake.wompi.test/s/{$id}",
            longUrl: "https://fake.wompi.test/EnlaceSuscripcion?id={$id}",
            qrUrl: "https://fake.wompi.test/qr/{$id}.jpg",
            isProductive: false,
        );
    }

    public function charge(#[SensitiveParameter] ChargeData $data): ChargeResult
    {
        if ($this->forceNetworkError) {
            throw new ConnectionException('Fake network error');
        }

        if ($this->forceChargeFailure) {
            return ChargeResult::failed($this->forceErrorCode ?? 'card_declined', 'Card declined (fake)');
        }

        $this->chargedAmounts[] = $data->amountCents;

        return ChargeResult::succeeded('fake_tx_'.Str::uuid()->toString());
    }

    public function refund(string $transactionId, int $amountCents, string $idempotencyKey): RefundResult
    {
        if ($this->forceNetworkError) {
            throw new ConnectionException('Fake network error');
        }

        $this->refundsIssued[] = ['transactionId' => $transactionId, 'amountCents' => $amountCents];

        return RefundResult::succeeded('fake_re_'.Str::uuid()->toString());
    }

    public function tokenize(#[SensitiveParameter] CardData $card): TokenResult
    {
        if ($this->forceTokenizeFailure) {
            return TokenResult::failed($this->forceErrorCode ?? 'card_declined');
        }

        return TokenResult::succeeded(
            token: 'fake_tok_'.Str::uuid()->toString(),
            last4: $card->last4(),
            brand: 'visa',
            expMonth: (int) $card->expMonth,
            expYear: (int) $card->expYear,
        );
    }

    public function detokenize(string $token): ?array
    {
        return ['last4' => '4242', 'brand' => 'visa', 'exp_month' => 12, 'exp_year' => 2030];
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if ($this->forceWebhookSignatureInvalid) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $this->webhookSecret), $signature);
    }

    public function getTransaction(string $transactionId): ?TransactionResult
    {
        return new TransactionResult($transactionId, $this->transactionStatus);
    }

    /**
     * Helper for webhook tests: produce a valid signature for a payload.
     */
    public function signWebhook(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->webhookSecret);
    }
}
