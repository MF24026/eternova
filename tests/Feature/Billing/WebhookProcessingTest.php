<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Jobs\ProcessWompiWebhookEvent;
use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\WebhookLog;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Drives the async job + handlers directly (no queue) to assert the domain effects of each
 * webhook event type: state transitions through the machine, billing-date updates, and
 * append-only audit entries.
 */
final class WebhookProcessingTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(string $status, string $reference = 'ref-1'): Subscription
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        return Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->create(['status' => $status, 'gateway_subscription_id' => $reference]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function process(array $payload): void
    {
        $log = WebhookLog::create([
            'gateway' => 'wompi',
            'event_type' => (string) ($payload['event'] ?? ''),
            'event_id' => 'evt-'.uniqid(),
            'status' => WebhookLog::STATUS_RECEIVED,
            'received_at' => now(),
            'payload' => $payload,
            'signature' => 'sig',
        ]);

        (new ProcessWompiWebhookEvent($log->id))->handle();

        $this->lastLog = $log->fresh();
    }

    private ?WebhookLog $lastLog = null;

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>
     */
    private function transactionUpdated(array $transaction): array
    {
        return ['event' => 'transaction.updated', 'data' => ['transaction' => $transaction]];
    }

    public function test_approved_charge_activates_a_trialing_subscription(): void
    {
        $sub = $this->subscription('trialing');

        $this->process($this->transactionUpdated([
            'id' => 'tx-1', 'reference' => 'ref-1', 'status' => 'APPROVED',
        ]));

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertNotNull($sub->next_billing_at);
        $this->assertNotNull($sub->last_paid_at);
        // State change produced an audit row.
        $this->assertDatabaseHas('billing_audit_log', ['subscription_id' => $sub->id, 'event_type' => 'subscription.state_changed']);
    }

    public function test_approved_charge_on_active_subscription_renews_dates_without_invalid_transition(): void
    {
        $sub = $this->subscription('active');

        $this->process($this->transactionUpdated([
            'id' => 'tx-2', 'reference' => 'ref-1', 'status' => 'APPROVED',
        ]));

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertNotNull($sub->next_billing_at);
        $this->assertSame(WebhookLog::STATUS_COMPLETED, $this->lastLog->status);
    }

    public function test_declined_charge_moves_active_to_past_due_without_scheduling_retry(): void
    {
        $sub = $this->subscription('active');

        $this->process($this->transactionUpdated([
            'id' => 'tx-3', 'reference' => 'ref-1', 'status' => 'DECLINED',
        ]));

        $sub->refresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertNotNull($sub->past_due_since);
        // Wompi owns retries now; we no longer schedule our own.
        $this->assertNull($sub->next_retry_at);
    }

    public function test_approved_recurring_charge_activates_trialing_by_id_enlace(): void
    {
        $sub = $this->subscription('trialing', 'enlace-9');
        $this->process(['event' => 'transaction.updated',
            'data' => ['idEnlace' => 'enlace-9', 'transaction' => ['id' => 'tx-r1', 'status' => 'APPROVED']]]);
        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_voided_charge_cancels_the_subscription(): void
    {
        $sub = $this->subscription('active');

        $this->process($this->transactionUpdated([
            'id' => 'tx-4', 'reference' => 'ref-1', 'status' => 'VOIDED',
        ]));

        $sub->refresh();
        $this->assertSame('canceled', $sub->status);
        $this->assertNotNull($sub->canceled_at);
    }

    public function test_unknown_reference_is_a_no_op(): void
    {
        $this->subscription('active', 'ref-known');

        $this->process($this->transactionUpdated([
            'id' => 'tx-5', 'reference' => 'ref-unknown', 'status' => 'APPROVED',
        ]));

        $this->assertSame(WebhookLog::STATUS_COMPLETED, $this->lastLog->status);
        $this->assertDatabaseMissing('billing_audit_log', ['event_type' => 'subscription.state_changed']);
    }

    public function test_refund_event_writes_an_audit_entry(): void
    {
        $sub = $this->subscription('active');

        $this->process([
            'event' => 'transaction.refunded',
            'data' => ['transaction' => ['id' => 'tx-6', 'reference' => 'ref-1', 'amount_in_cents' => 2900]],
        ]);

        $this->assertDatabaseHas('billing_audit_log', [
            'subscription_id' => $sub->id,
            'event_type' => 'transaction.refunded',
        ]);
    }

    public function test_chargeback_event_is_recorded_without_suspending(): void
    {
        $sub = $this->subscription('active');

        $this->process([
            'event' => 'chargeback.created',
            'data' => ['chargeback' => ['id' => 'cb-1', 'reference' => 'ref-1', 'amount_in_cents' => 2900]],
        ]);

        $this->assertDatabaseHas('billing_audit_log', [
            'subscription_id' => $sub->id,
            'event_type' => 'chargeback.created',
        ]);
        $this->assertSame('active', $sub->fresh()->status, 'A chargeback must not auto-suspend.');
    }

    public function test_unknown_event_type_is_acknowledged_and_ignored(): void
    {
        $this->process(['event' => 'something.unsupported', 'data' => []]);

        $this->assertSame(WebhookLog::STATUS_COMPLETED, $this->lastLog->status);
        $this->assertStringContainsString('unknown_event_type', (string) $this->lastLog->error);
    }
}
