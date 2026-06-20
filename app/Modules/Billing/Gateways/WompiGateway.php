<?php

declare(strict_types=1);

namespace App\Modules\Billing\Gateways;

use App\Modules\Billing\Enums\GatewayError;
use App\Modules\Billing\Exceptions\GatewayException;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\ChargeResult;
use App\Modules\Billing\Gateways\Data\RecurringPaymentLink;
use App\Modules\Billing\Gateways\Data\RecurringPlanData;
use App\Modules\Billing\Gateways\Data\RefundResult;
use App\Modules\Billing\Gateways\Data\TokenResult;
use App\Modules\Billing\Gateways\Data\TransactionResult;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use App\Modules\Billing\Support\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * Wompi **El Salvador** implementation of the payment gateway seam. SV uses Spanish endpoints/
 * fields and **OAuth2 client_credentials** (App ID + API Secret) — NOT a public/private key pair.
 * Full spec: docs/billing/wompi-sv-integration.md (provider Q&A + https://docs.wompi.sv).
 *
 * Auth: POST {authBaseUrl}/connect/token with grant_type=client_credentials, audience=wompi_api,
 * client_id=App ID, client_secret=API Secret → a Bearer access_token (3600s), cached here and
 * sent as `Authorization: Bearer` to {baseUrl}. The same API Secret is the `wompi_hash` webhook
 * HMAC key.
 *
 * Confirmed: charge `POST /TransaccionCompra` (`monto` in USD dollars, `esAprobada` bool,
 * `idExterno` dedupe), tokenize `POST /TokenesTarjeta` (integer exp) → `tokenTarjeta`, refund
 * `POST /Reembolsos`, $1,000 cap, Visa/Mastercard only.
 *
 * ASSUMPTIONS to confirm against a live (non-productive) call: the stored-token charge field in
 * /TransaccionCompra (assumed `tokenTarjeta`), the tokenize response metadata field names, and
 * the consult endpoint path. Marked inline.
 *
 * PCI discipline: api_secret is #[\SensitiveParameter]; the response body and the access token
 * are never logged; the thrown GatewayException carries no `previous`.
 */
final class WompiGateway implements PaymentGatewayInterface
{
    /** Wompi SV rejects transactions above $1,000 USD. */
    private const MAX_TRANSACTION_CENTS = 100_000;

    public function __construct(
        private readonly string $appId,
        #[SensitiveParameter] private readonly string $apiSecret,
        private readonly string $authBaseUrl,
        private readonly string $baseUrl,
        private readonly WompiErrorTranslator $errorTranslator,
        private readonly CircuitBreaker $apiBreaker,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Create a Wompi-managed recurring subscription link (validated 2026-06-20, non-productive):
     * POST /EnlacePagoRecurrente {diaDePago, nombre, idAplicativo, monto, descripcionProducto}
     * -> {idEnlace, urlEnlace, urlEnlaceLargo, estaProductivo, urlQrCodeEnlace}. Wompi owns the
     * recurrence; we store idEnlace and react to webhooks.
     */
    public function createRecurringPaymentLink(RecurringPlanData $data): RecurringPaymentLink
    {
        $correlationId = Str::uuid()->toString();

        if ($data->amountCents > self::MAX_TRANSACTION_CENTS) {
            return RecurringPaymentLink::failed(GatewayError::CardDeclined->value);
        }

        try {
            $response = $this->authorized()->post("{$this->baseUrl}/EnlacePagoRecurrente", [
                'diaDePago' => $data->dayOfMonth,
                'nombre' => $data->name,
                'idAplicativo' => $this->appId,
                'monto' => round($data->amountCents / 100, 2),
                'descripcionProducto' => $data->description,
            ]);

            if (! $response->successful()) {
                $this->logFailure('recurring_link_http_error', $response->status(), $correlationId);

                return RecurringPaymentLink::failed(GatewayError::ProcessingError->value);
            }

            $linkId = (string) $response->json('idEnlace');

            if ($linkId === '') {
                return RecurringPaymentLink::failed(GatewayError::ProcessingError->value);
            }

            return RecurringPaymentLink::succeeded(
                linkId: $linkId,
                shortUrl: (string) $response->json('urlEnlace'),
                longUrl: $response->json('urlEnlaceLargo'),
                qrUrl: $response->json('urlQrCodeEnlace'),
                isProductive: (bool) $response->json('estaProductivo', false),
            );
        } catch (Throwable $e) {
            $this->logException('recurring_link_exception', $e, $correlationId);
            throw new GatewayException('Recurring link creation failed');
        }
    }

    public function charge(#[SensitiveParameter] ChargeData $data): ChargeResult
    {
        $correlationId = Str::uuid()->toString();

        if ($data->amountCents > self::MAX_TRANSACTION_CENTS) {
            // Non-retryable: above Wompi SV's hard per-transaction cap (won't occur for normal plans).
            return ChargeResult::failed(GatewayError::CardDeclined->value, 'Amount exceeds the Wompi SV per-transaction limit.');
        }

        try {
            // OPEN QUESTION (blocks live recurring billing): the endpoint + body for an
            // UNATTENDED tokenized charge (no 3DS) is NOT in the public docs. The 2026-06-19
            // smoke found that POST /TransaccionCompra is 403 and POST /TransaccionCompra/3DS is
            // the interactive 3DS flow (requires full billing address + a redirect URL — not
            // usable for recurring). Wompi support must provide the recurring-charge endpoint;
            // the path/field below are a placeholder until then. See wompi-sv-integration.md.
            return $this->apiBreaker->execute(function () use ($data, $correlationId): ChargeResult {
                $response = $this->authorized()
                    ->post("{$this->baseUrl}/TransaccionCompra", [
                        'monto' => round($data->amountCents / 100, 2),
                        'emailCliente' => $data->customerEmail,
                        'nombreCliente' => $data->customerName ?? $data->customerEmail,
                        'token' => $data->cardToken,
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
            $response = $this->authorized()
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
            // CONFIRMED against the sandbox (non-productive smoke, 2026-06-19):
            // POST /Tokenizacion {numeroTarjeta, cvv, mesVencimiento:int, anioVencimiento:int}
            // -> {"token":"...","tarjetaEnmascarada":"5200 0000 XXXX 2235 "}
            $response = $this->authorized()
                ->post("{$this->baseUrl}/Tokenizacion", [
                    'numeroTarjeta' => $card->number,
                    'cvv' => $card->cvv,
                    'mesVencimiento' => (int) $card->expMonth,
                    'anioVencimiento' => (int) $card->expYear,
                ]);

            if (! $response->successful()) {
                $this->logFailure('tokenize_http_error', $response->status(), $correlationId);

                return TokenResult::failed(
                    $this->errorTranslator->translate((string) $response->json('mensaje', 'UNKNOWN'))->value
                );
            }

            // The SV response has no brand and no separate last4 — derive last4 from the masked
            // PAN ("5200 0000 XXXX 2235 " -> "2235").
            $masked = preg_replace('/\D/', '', (string) $response->json('tarjetaEnmascarada', '')) ?? '';
            $last4 = $masked !== '' ? substr($masked, -4) : $card->last4();

            return TokenResult::succeeded(
                token: (string) $response->json('token'),
                last4: $last4,
                brand: 'unknown',
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
        $expected = hash_hmac('sha256', $payload, $this->apiSecret);

        return hash_equals($expected, $signature);
    }

    public function getTransaction(string $transactionId): ?TransactionResult
    {
        try {
            // ASSUMPTION: transaction-consult endpoint path — confirm against the docs/a live call.
            $response = $this->authorized()->get("{$this->baseUrl}/TransaccionConsulta/{$transactionId}");

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

    /**
     * A pending HTTP request pre-authorized with a fresh Bearer token (15s timeout).
     */
    private function authorized(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken())->timeout(15);
    }

    /**
     * OAuth2 client_credentials token, cached until shortly before it expires. App ID is the
     * client_id, API Secret the client_secret. The token is never logged.
     */
    private function accessToken(): string
    {
        $cacheKey = "wompi:token:{$this->appId}";
        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()->timeout(15)->post("{$this->authBaseUrl}/connect/token", [
            'grant_type' => 'client_credentials',
            'audience' => 'wompi_api',
            'client_id' => $this->appId,
            'client_secret' => $this->apiSecret,
        ]);

        if (! $response->successful()) {
            // Never include the response body — it could echo the secret.
            throw new GatewayException('Wompi authentication failed');
        }

        $token = (string) $response->json('access_token');
        $ttl = max(60, (int) $response->json('expires_in', 3600) - 60);
        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
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
