<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Exceptions\GatewayException;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\Data\CardData;
use App\Modules\Billing\Gateways\Data\ChargeData;
use App\Modules\Billing\Gateways\Support\WompiErrorTranslator;
use App\Modules\Billing\Gateways\WompiGateway;
use App\Modules\Billing\Support\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;
use SensitiveParameter;
use Tests\TestCase;

/**
 * PCI-discipline guardrails. These exist because the failure mode (a leaked PAN or token in
 * a log/trace) is exactly the thing that turns a payment integration into a breach.
 */
final class BillingPciSmokeTest extends TestCase
{
    private function spyLogger(): object
    {
        return new class extends AbstractLogger
        {
            /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }

            public function dump(): string
            {
                return json_encode($this->records, JSON_THROW_ON_ERROR);
            }
        };
    }

    private function wompiGateway(object $logger): WompiGateway
    {
        return new WompiGateway(
            appId: 'app_test',
            apiSecret: 'api_secret_test',
            authBaseUrl: 'https://id.wompi.test',
            baseUrl: 'https://api.wompi.test',
            errorTranslator: new WompiErrorTranslator(),
            apiBreaker: new CircuitBreaker('pci-test:'.uniqid(), threshold: 99, cooldownSeconds: 60),
            logger: $logger,
        );
    }

    public function test_sensitive_parameter_attributes_are_present_on_the_gateway(): void
    {
        $cases = [
            [WompiGateway::class, 'charge', 'data', true],
            [WompiGateway::class, 'tokenize', 'card', true],
            [WompiGateway::class, '__construct', 'apiSecret', true],
            [WompiGateway::class, '__construct', 'appId', false],
            [WompiGateway::class, '__construct', 'authBaseUrl', false],
            [WompiGateway::class, '__construct', 'baseUrl', false],
        ];

        foreach ($cases as [$class, $method, $paramName, $shouldBeSensitive]) {
            $param = collect((new ReflectionMethod($class, $method))->getParameters())
                ->firstWhere(fn ($p) => $p->getName() === $paramName);

            $this->assertNotNull($param, "{$class}::{$method}() should have a \${$paramName} parameter.");

            $isSensitive = $param->getAttributes(SensitiveParameter::class) !== [];
            $this->assertSame(
                $shouldBeSensitive,
                $isSensitive,
                "{$class}::{$method}() param \${$paramName} #[SensitiveParameter] mismatch."
            );
        }
    }

    public function test_interface_marks_card_inputs_sensitive(): void
    {
        foreach (['charge' => 'data', 'tokenize' => 'card'] as $method => $paramName) {
            $param = collect((new ReflectionMethod(PaymentGatewayInterface::class, $method))->getParameters())
                ->firstWhere(fn ($p) => $p->getName() === $paramName);

            $this->assertNotEmpty(
                $param->getAttributes(SensitiveParameter::class),
                "PaymentGatewayInterface::{$method}() \${$paramName} must be #[SensitiveParameter]."
            );
        }
    }

    public function test_sensitive_parameter_redacts_the_value_in_a_stack_trace(): void
    {
        $boom = function (#[SensitiveParameter] string $secret): never {
            throw new RuntimeException('boom');
        };

        try {
            $boom('4111111111111111');
            $this->fail('Expected exception.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('4111111111111111', $e->getTraceAsString());
        }
    }

    public function test_card_token_is_redacted_in_the_trace_when_a_charge_throws(): void
    {
        Cache::flush();
        Http::fake(fn () => throw new ConnectionException('network down'));

        $gateway = $this->wompiGateway($this->spyLogger());
        $data = new ChargeData(
            amountCents: 2900,
            currency: 'USD',
            customerEmail: 'o@t.test',
            cardToken: 'tok_SECRET_LEAK_VALUE',
            reference: 'ref-1',
        );

        try {
            $gateway->charge($data);
            $this->fail('Expected GatewayException.');
        } catch (GatewayException $e) {
            $this->assertNull($e->getPrevious(), 'GatewayException must not chain the original (trace may hold a token).');
            $this->assertStringNotContainsString('tok_SECRET_LEAK_VALUE', $e->getTraceAsString());
        }
    }

    public function test_gateway_does_not_log_the_response_body_on_failure(): void
    {
        Cache::flush();
        Http::fake([
            // Auth succeeds; the purchase endpoint fails with a body that must not be logged.
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600], 200),
            '*' => Http::response(['error' => ['reason' => 'CARD_DECLINED'], 'leak' => 'TOKEN_LEAK_IN_BODY'], 500),
        ]);

        $logger = $this->spyLogger();
        $gateway = $this->wompiGateway($logger);

        $result = $gateway->charge(new ChargeData(2900, 'USD', 'o@t.test', 'tok_x', 'ref-2'));

        $this->assertFalse($result->isSuccess());
        $this->assertStringNotContainsString('TOKEN_LEAK_IN_BODY', $logger->dump());
    }

    public function test_card_data_debug_info_masks_the_pan_and_cvv(): void
    {
        $debug = (new CardData('4111111111111111', '123', '12', '2030'))->__debugInfo();

        $this->assertSame('****1111', $debug['number']);
        $this->assertSame('***', $debug['cvv']);
        $this->assertStringNotContainsString('4111111111111111', json_encode($debug, JSON_THROW_ON_ERROR));
    }

    public function test_charge_data_debug_info_masks_the_token(): void
    {
        $debug = (new ChargeData(2900, 'USD', 'o@t.test', 'tok_secret', 'ref'))->__debugInfo();

        $this->assertSame('***redacted***', $debug['cardToken']);
    }
}
