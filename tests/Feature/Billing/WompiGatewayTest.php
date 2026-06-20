<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Data\RecurringPlanData;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use App\Modules\Billing\Gateways\WompiGateway;
use App\Modules\Billing\Support\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Log\AbstractLogger;
use Tests\TestCase;

/**
 * Verifies the WompiGateway speaks the Wompi **SV** wire format (Spanish endpoints/fields,
 * `monto` in dollars, `esAprobada` bool, `idExterno` dedupe) — see
 * docs/billing/wompi-sv-integration.md. Network is faked; no real keys.
 */
final class WompiGatewayTest extends TestCase
{
    private function gateway(): WompiGateway
    {
        Cache::flush();
        $logger = new class extends AbstractLogger
        {
            public function log($level, string|\Stringable $message, array $context = []): void {}
        };

        return new WompiGateway(
            appId: 'app-test',
            apiSecret: 'api_secret_test',
            authBaseUrl: 'https://id.wompi.test',
            baseUrl: 'https://api.wompi.test',
            errorTranslator: new WompiErrorTranslator(),
            apiBreaker: new CircuitBreaker('wompi-sv-test:'.uniqid(), threshold: 99, cooldownSeconds: 60),
            logger: $logger,
        );
    }

    /**
     * The OAuth token endpoint fake, merged into every API fake so accessToken() resolves.
     *
     * @return array<string, \Illuminate\Http\Client\Response>
     */
    private function withAuth(array $endpoints): array
    {
        return array_merge(
            ['*/connect/token' => Http::response(['access_token' => 'tok-abc', 'expires_in' => 3600, 'token_type' => 'Bearer'], 200)],
            $endpoints,
        );
    }

    private function chargeData(int $amountCents = 900): ChargeData
    {
        return new ChargeData(
            amountCents: $amountCents,
            currency: 'USD',
            customerEmail: 'owner@tenant.test',
            cardToken: 'tok-x',
            reference: 'ref-1',
            tenantId: 'TENANT',
            customerName: 'Floreria Demo',
        );
    }

    public function test_approved_charge_parses_es_aprobada_and_sends_sv_body(): void
    {
        Http::fake($this->withAuth([
            '*/TransaccionCompra' => Http::response(['esAprobada' => true, 'idTransaccion' => 'tx-sv-1'], 200),
        ]));

        $result = $this->gateway()->charge($this->chargeData(900));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('tx-sv-1', $result->transactionId);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/TransaccionCompra')
                && $body['monto'] === 9.0          // dollars, not cents
                && $body['idExterno'] === 'ref-1'  // dedupe field, not a header
                && $body['token'] === 'tok-x'
                && $body['nombreCliente'] === 'Floreria Demo';
        });
    }

    public function test_declined_charge_is_a_card_decline(): void
    {
        Http::fake($this->withAuth([
            '*/TransaccionCompra' => Http::response(['esAprobada' => false, 'mensaje' => 'Fondos insuficientes'], 200),
        ]));

        $result = $this->gateway()->charge($this->chargeData());

        $this->assertFalse($result->isSuccess());
        $this->assertSame('card_declined', $result->errorCode);
    }

    public function test_http_error_is_a_processing_error(): void
    {
        Http::fake($this->withAuth(['*/TransaccionCompra' => Http::response(['mensaje' => 'boom'], 500)]));

        $result = $this->gateway()->charge($this->chargeData());

        $this->assertFalse($result->isSuccess());
        $this->assertSame('processing_error', $result->errorCode);
    }

    public function test_amount_over_the_sv_cap_is_rejected_without_calling_the_gateway(): void
    {
        Http::fake();

        $result = $this->gateway()->charge($this->chargeData(100_100)); // $1,001 > $1,000 cap

        $this->assertFalse($result->isSuccess());
        Http::assertNothingSent();
    }

    public function test_tokenize_parses_token_and_derives_last4_from_masked_pan(): void
    {
        // Real SV /Tokenizacion shape (validated 2026-06-19 in non-productive mode).
        Http::fake($this->withAuth([
            '*/Tokenizacion' => Http::response(['token' => 'tok-sv', 'tarjetaEnmascarada' => '5200 0000 XXXX 2235 '], 200),
        ]));

        $result = $this->gateway()->tokenize(new CardData('5200000000002235', '123', '1', '2029'));

        $this->assertTrue($result->isSuccess());
        $this->assertSame('tok-sv', $result->token);
        $this->assertSame('2235', $result->last4);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/Tokenizacion')) {
                return false;
            }
            $body = $request->data();

            return $body['numeroTarjeta'] === '5200000000002235'
                && $body['mesVencimiento'] === 1     // integer, not "01"
                && $body['anioVencimiento'] === 2029;
        });
    }

    public function test_create_recurring_link_parses_the_enlace_response(): void
    {
        // Real SV /EnlacePagoRecurrente shape (validated 2026-06-20 in non-productive mode).
        Http::fake($this->withAuth([
            '*/EnlacePagoRecurrente' => Http::response([
                'idEnlace' => 'enlace-1',
                'urlEnlace' => 'https://s.wompi.sv/abc',
                'urlEnlaceLargo' => 'https://cargosautomaticos.wompi.sv/x',
                'estaProductivo' => false,
                'urlQrCodeEnlace' => 'https://img/qr.jpg',
            ], 200),
        ]));

        $link = $this->gateway()->createRecurringPaymentLink(new RecurringPlanData(2900, 15, 'Pro', 'Mensual'));

        $this->assertTrue($link->isSuccess());
        $this->assertSame('enlace-1', $link->linkId);
        $this->assertSame('https://s.wompi.sv/abc', $link->shortUrl);
        $this->assertFalse($link->isProductive);

        Http::assertSent(function ($request): bool {
            if (! str_ends_with($request->url(), '/EnlacePagoRecurrente')) {
                return false;
            }
            $b = $request->data();

            return $b['monto'] === 29.0 && $b['diaDePago'] === 15 && $b['idAplicativo'] === 'app-test';
        });
    }

    public function test_get_transaction_normalizes_es_aprobada_to_a_status(): void
    {
        Http::fake($this->withAuth([
            '*/TransaccionConsulta/*' => Http::response(['idTransaccion' => 'tx-9', 'esAprobada' => true, 'monto' => 9.00], 200),
        ]));

        $tx = $this->gateway()->getTransaction('tx-9');

        $this->assertNotNull($tx);
        $this->assertSame('APPROVED', $tx->status);
        $this->assertSame(900, $tx->amountCents);
    }
}
