<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\FakeGateway;
use App\Modules\Billing\Jobs\ProcessWompiWebhookEvent;
use App\Modules\Billing\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The webhook receiver: HMAC gate, idempotent receipt, fast 204 + queued processing.
 * Server-to-server endpoint with no UI, so PHPUnit (real HTTP via the kernel) is the right
 * layer — Playwright covers UI flows, not machine callbacks.
 */
final class WompiWebhookReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/billing/webhooks/wompi';

    private function gateway(): FakeGateway
    {
        /** @var FakeGateway $g */
        $g = app(PaymentGatewayInterface::class);

        return $g;
    }

    private function approvedPayload(string $txId = 'tx-1', string $reference = 'ref-1'): string
    {
        return json_encode([
            'event' => 'transaction.updated',
            'data' => ['transaction' => [
                'id' => $txId,
                'reference' => $reference,
                'status' => 'APPROVED',
                'amount_in_cents' => 2900,
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private function postWebhook(string $payload, ?string $signature = null): TestResponse
    {
        $signature ??= $this->gateway()->signWebhook($payload);

        return $this->call(
            'POST',
            self::URI,
            [],
            [],
            [],
            ['HTTP_X_EVENT_CHECKSUM' => $signature, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            $payload,
        );
    }

    public function test_missing_signature_is_rejected(): void
    {
        $this->postWebhook($this->approvedPayload(), signature: '')->assertStatus(401);
        $this->assertDatabaseCount('webhook_log', 0);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $this->postWebhook($this->approvedPayload(), signature: 'not-the-real-hmac')->assertStatus(401);
        $this->assertDatabaseCount('webhook_log', 0);
    }

    public function test_malformed_payload_is_rejected(): void
    {
        $this->postWebhook('not-json-at-all')->assertStatus(400);
    }

    public function test_missing_event_id_is_rejected(): void
    {
        $payload = json_encode(['event' => 'transaction.updated', 'data' => ['transaction' => ['status' => 'APPROVED']]], JSON_THROW_ON_ERROR);

        $this->postWebhook($payload)->assertStatus(400);
        $this->assertDatabaseCount('webhook_log', 0);
    }

    public function test_valid_event_is_accepted_logged_and_queued(): void
    {
        Queue::fake();

        $this->postWebhook($this->approvedPayload())->assertNoContent();

        $this->assertDatabaseHas('webhook_log', [
            'gateway' => 'wompi',
            'event_id' => 'tx-1',
            'event_type' => 'transaction.updated',
            'status' => WebhookLog::STATUS_RECEIVED,
        ]);
        Queue::assertPushed(ProcessWompiWebhookEvent::class, 1);
    }

    public function test_duplicate_event_is_acknowledged_but_not_reprocessed(): void
    {
        Queue::fake();

        $payload = $this->approvedPayload();
        $this->postWebhook($payload)->assertNoContent();
        $this->postWebhook($payload)->assertNoContent();

        $this->assertDatabaseCount('webhook_log', 1);
        Queue::assertPushed(ProcessWompiWebhookEvent::class, 1);
    }
}
