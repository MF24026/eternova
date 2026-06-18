<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways;

use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\ChargeResult;
use App\Modules\Billing\Gateways\Data\RefundResult;
use App\Modules\Billing\Gateways\Data\TokenResult;
use App\Modules\Billing\Gateways\Data\TransactionResult;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use App\Modules\Billing\Support\CircuitBreaker;
use App\Modules\Billing\Exceptions\GatewayException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Wompi (Colombia/El Salvador) implementation of the payment gateway seam.
 *
 * PCI discipline enforced here, not by convention:
 *   - private key / events secret are #[\SensitiveParameter] so they stay out of traces.
 *   - the gateway response body is NEVER logged or chained into an exception — it can carry
 *     a token. Only a status code + correlation id are logged.
 *   - thrown GatewayException carries no `previous`, so the original (which may reference a
 *     card token in its trace) is not propagated.
 *
 * NOTE: real-endpoint shapes (refund route, token fields) are finalized against the Wompi
 * sandbox in the Phase 2 smoke once credentials are available; the structure here follows
 * Wompi's documented v1 API.
 */
final class WompiGateway implements PaymentGatewayInterface
{
    public function __construct(
        #[SensitiveParameter] private readonly string $privateKey,
        private readonly string $publicKey,
        #[SensitiveParameter] private readonly string $eventsSecret,
        private readonly string $baseUrl,
        private readonly WompiErrorTranslator $errorTranslator,
        private readonly CircuitBreaker $apiBreaker,
        private readonly LoggerInterface $logger,
    ) {}

    public function charge(#[SensitiveParameter] ChargeData $data): ChargeResult
    {
        $correlationId = Str::uuid()->toString();

        try {
            return $this->apiBreaker->execute(function () use ($data, $correlationId): ChargeResult {
                $response = Http::withToken($this->privateKey)
                    ->timeout(15)
                    ->post("{$this->baseUrl}/v1/transactions", [
                        'amount_in_cents' => $data->amountCents,
                        'currency' => $data->currency,
                        'customer_email' => $data->customerEmail,
                        'payment_method' => ['type' => 'CARD', 'installments' => 1, 'token' => $data->cardToken],
                        'reference' => $data->reference,
                    ]);

                if (! $response->successful()) {
                    // Do NOT log $response->body() — it may contain a token.
                    $this->logFailure('charge_http_error', $response->status(), $correlationId);

                    return ChargeResult::failed(
                        $this->errorTranslator->translate((string) $response->json('error.reason', 'UNKNOWN'))->value,
                        'Payment failed',
                    );
                }

                $status = (string) $response->json('data.status', 'ERROR');

                if ($status !== 'APPROVED') {
                    return ChargeResult::failed(
                        $this->errorTranslator->translate((string) $response->json('data.status_message', $status))->value,
                        'Payment declined',
                    );
                }

                return ChargeResult::succeeded((string) $response->json('data.id'));
            });
        } catch (Throwable $e) {
            $this->logException('charge_exception', $e, $correlationId);
            throw new GatewayException('Charge failed');
        }
    }

    public function refund(string $transactionId, int $amountCents, string $idempotencyKey): RefundResult
    {
        $correlationId = Str::uuid()->toString();

        try {
            $response = Http::withToken($this->privateKey)
                ->timeout(15)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post("{$this->baseUrl}/v1/refunds", [
                    'transaction_id' => $transactionId,
                    'amount_in_cents' => $amountCents,
                ]);

            if (! $response->successful()) {
                $this->logFailure('refund_http_error', $response->status(), $correlationId);

                return RefundResult::failed(
                    $this->errorTranslator->translate((string) $response->json('error.reason', 'UNKNOWN'))->value,
                    'Refund failed',
                );
            }

            return RefundResult::succeeded((string) $response->json('data.id'));
        } catch (Throwable $e) {
            $this->logException('refund_exception', $e, $correlationId);
            throw new GatewayException('Refund failed');
        }
    }

    public function tokenize(#[SensitiveParameter] CardData $card): TokenResult
    {
        $correlationId = Str::uuid()->toString();

        try {
            // Tokenization uses the PUBLIC key (same endpoint the frontend iframe hits).
            $response = Http::withToken($this->publicKey)
                ->timeout(15)
                ->post("{$this->baseUrl}/v1/tokens/cards", [
                    'number' => $card->number,
                    'cvc' => $card->cvv,
                    'exp_month' => $card->expMonth,
                    'exp_year' => $card->expYear,
                    'card_holder' => $card->holderName ?? '',
                ]);

            if (! $response->successful()) {
                $this->logFailure('tokenize_http_error', $response->status(), $correlationId);

                return TokenResult::failed(
                    $this->errorTranslator->translate((string) $response->json('error.reason', 'UNKNOWN'))->value
                );
            }

            return TokenResult::succeeded(
                token: (string) $response->json('data.id'),
                last4: (string) $response->json('data.last_four', $card->last4()),
                brand: (string) $response->json('data.brand', 'unknown'),
                expMonth: (int) $card->expMonth,
                expYear: (int) $card->expYear,
            );
        } catch (Throwable $e) {
            $this->logException('tokenize_exception', $e, $correlationId);
            throw new GatewayException('Tokenization failed');
        }
    }

    public function detokenize(string $token): ?array
    {
        // Wompi exposes no detokenization endpoint; display metadata (last4/brand/exp) is
        // captured at tokenize time and stored on the subscription. Callers use that.
        return null;
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->eventsSecret);

        return hash_equals($expected, $signature);
    }

    public function getTransaction(string $transactionId): ?TransactionResult
    {
        try {
            $response = Http::withToken($this->privateKey)
                ->timeout(15)
                ->get("{$this->baseUrl}/v1/transactions/{$transactionId}");

            if (! $response->successful()) {
                return null;
            }

            return new TransactionResult(
                id: (string) $response->json('data.id', $transactionId),
                status: (string) $response->json('data.status', 'UNKNOWN'),
                amountCents: $response->json('data.amount_in_cents'),
                currency: $response->json('data.currency'),
            );
        } catch (Throwable $e) {
            $this->logException('get_transaction_exception', $e, Str::uuid()->toString());

            return null;
        }
    }

    private function logFailure(string $event, int $status, string $correlationId): void
    {
        $this->logger->warning($event, ['status' => $status, 'correlation_id' => $correlationId]);
    }

    /**
     * Log only safe fields. NEVER the message body or the exception's previous chain.
     */
    private function logException(string $event, Throwable $e, string $correlationId): void
    {
        $this->logger->error($event, [
            'error_class' => $e::class,
            'error_message' => $e->getMessage(),
            'correlation_id' => $correlationId,
        ]);
    }
}
