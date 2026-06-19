<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways;

use App\Modules\Billing\Enums\GatewayError;
use App\Modules\Billing\Exceptions\GatewayException;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\ChargeResult;
use App\Modules\Billing\Gateways\Data\RefundResult;
use App\Modules\Billing\Gateways\Data\TokenResult;
use App\Modules\Billing\Gateways\Data\TransactionResult;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use App\Modules\Billing\Support\CircuitBreaker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Wompi **El Salvador** implementation of the payment gateway seam. The SV API uses Spanish
 * endpoints/fields and differs from Wompi Colombia — see docs/billing/wompi-sv-integration.md
 * (provider Q&A + https://docs.wompi.sv) for the source of truth.
 *
 * Confirmed from the docs:
 *   - charge `POST /TransaccionCompra` with `monto` (USD dollars, NOT cents), `emailCliente`,
 *     `nombreCliente`, `idExterno` (dedupe; there is NO Idempotency-Key header); response is
 *     `idTransaccion` + `esAprobada` (bool — no status string) + `mensaje` (free-text error).
 *   - tokenize `POST /TokenesTarjeta` (exp as INTEGER mesVencimiento/anioVencimiento) -> `tokenTarjeta`.
 *   - webhook signature: header `wompi_hash` = HMAC-SHA256(raw body, API Secret).
 *   - max $1,000 USD per transaction; Visa/Mastercard only.
 *
 * ASSUMPTIONS still to confirm against a live (non-productive) call: the field name for charging
 * a STORED token in /TransaccionCompra (assumed `tokenTarjeta`), the tokenize response metadata
 * field names, and the transaction-consult endpoint path. Marked inline.
 *
 * PCI discipline (unchanged): credentials are #[\SensitiveParameter]; the response body is never
 * logged; the thrown GatewayException carries no `previous` (its trace could hold a token).
 */
final class WompiGateway implements PaymentGatewayInterface
{
    /** Wompi SV rejects transactions above $1,000 USD. */
    private const MAX_TRANSACTION_CENTS = 100_000;

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

        if ($data->amountCents > self::MAX_TRANSACTION_CENTS) {
            // Non-retryable: above Wompi SV's hard per-transaction cap. Treated as a decline so
            // dunning does not loop on it (will not occur for normal plan prices).
            return ChargeResult::failed(GatewayError::CardDeclined->value, 'Amount exceeds the Wompi SV per-transaction limit.');
        }

        try {
            return $this->apiBreaker->execute(function () use ($data, $correlationId): ChargeResult {
                $response = Http::withToken($this->privateKey)
                    ->timeout(15)
                    ->post("{$this->baseUrl}/TransaccionCompra", [
                        'monto' => round($data->amountCents / 100, 2),
                        'emailCliente' => $data->customerEmail,
                        'nombreCliente' => $data->customerName ?? $data->customerEmail,
                        // ASSUMPTION: stored-token charge field name in /TransaccionCompra.
                        'tokenTarjeta' => $data->cardToken,
                        'idExterno' => $data->reference,
                        'cantidadCuotas' => 1,
                    ]);

                if (! $response->successful()) {
                    // Do NOT log $response->body() — it may contain a token.
                    $this->logFailure('charge_http_error', $response->status(), $correlationId);

                    return ChargeResult::failed(GatewayError::ProcessingError->value, 'Payment failed');
                }

                // SV approval is the `esAprobada` boolean; `mensaje` is free Spanish text, so a
                // non-approval is classified as a (non-retryable) decline.
                if ($response->json('esAprobada') !== true) {
                    return ChargeResult::failed(GatewayError::CardDeclined->value, 'Payment declined');
                }

                return ChargeResult::succeeded((string) $response->json('idTransaccion'));
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
                ->post("{$this->baseUrl}/Reembolsos", [
                    'idTransaccion' => $transactionId,
                    'monto' => round($amountCents / 100, 2),
                    'idExterno' => $idempotencyKey,   // SV dedupe — no Idempotency-Key header
                ]);

            if (! $response->successful()) {
                $this->logFailure('refund_http_error', $response->status(), $correlationId);

                return RefundResult::failed(
                    $this->errorTranslator->translate((string) $response->json('mensaje', 'UNKNOWN'))->value,
                    'Refund failed',
                );
            }

            return RefundResult::succeeded((string) ($response->json('idReembolso') ?? $response->json('idTransaccion') ?? $transactionId));
        } catch (Throwable $e) {
            $this->logException('refund_exception', $e, $correlationId);
            throw new GatewayException('Refund failed');
        }
    }

    public function tokenize(#[SensitiveParameter] CardData $card): TokenResult
    {
        $correlationId = Str::uuid()->toString();

        try {
            // Tokenization uses the PUBLIC key (same endpoint the hosted-fields iframe hits).
            $response = Http::withToken($this->publicKey)
                ->timeout(15)
                ->post("{$this->baseUrl}/TokenesTarjeta", [
                    'numeroTarjeta' => $card->number,
                    'cvv' => $card->cvv,
                    'mesVencimiento' => (int) $card->expMonth,
                    'anioVencimiento' => (int) $card->expYear,
                    'nombreTarjetaHabiente' => $card->holderName ?? '',
                ]);

            if (! $response->successful()) {
                $this->logFailure('tokenize_http_error', $response->status(), $correlationId);

                return TokenResult::failed(
                    $this->errorTranslator->translate((string) $response->json('mensaje', 'UNKNOWN'))->value
                );
            }

            // ASSUMPTION: tokenize response metadata field names — confirm against a live call.
            return TokenResult::succeeded(
                token: (string) $response->json('tokenTarjeta'),
                last4: (string) ($response->json('ultimosDigitos') ?? $card->last4()),
                brand: (string) ($response->json('marca') ?? 'unknown'),
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

    /**
     * Verify the `wompi_hash` header: HMAC-SHA256 of the raw body keyed by the API Secret.
     * https://docs.wompi.sv/webhook/validar-webhook
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expected = hash_hmac('sha256', $payload, $this->eventsSecret);

        return hash_equals($expected, $signature);
    }

    public function getTransaction(string $transactionId): ?TransactionResult
    {
        try {
            // ASSUMPTION: transaction-consult endpoint path — confirm against the docs/a live call.
            $response = Http::withToken($this->privateKey)
                ->timeout(15)
                ->get("{$this->baseUrl}/TransaccionConsulta/{$transactionId}");

            if (! $response->successful()) {
                return null;
            }

            $monto = $response->json('monto');

            return new TransactionResult(
                id: (string) $response->json('idTransaccion', $transactionId),
                // Normalize SV's esAprobada bool to a status string for the reconcile comparison.
                status: $response->json('esAprobada') === true ? 'APPROVED' : 'DECLINED',
                amountCents: $monto !== null ? (int) round(((float) $monto) * 100) : null,
                currency: 'USD',
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
