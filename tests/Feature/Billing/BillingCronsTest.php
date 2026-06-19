<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Events\TrialEndingSoon;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\FakeGateway;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Support\BillingMaintenance;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The billing daily heartbeat. Each command is exercised against FakeGateway (force flags
 * drive success/decline) on real seeded subscriptions — no network, no UI, PHPUnit-only.
 */
final class BillingCronsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // A successful renewal now issues an invoice (PDF) + owner notification via the Phase 5
        // listeners; fake both so the cron tests stay hermetic.
        Storage::fake();
        Notification::fake();
    }

    private function gateway(): FakeGateway
    {
        /** @var FakeGateway $g */
        $g = app(PaymentGatewayInterface::class);

        return $g;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscription(string $status, array $attributes = []): Subscription
    {
        $tenant = Tenant::factory()->create(['email' => 'owner@tenant.test']);
        $plan = Plan::factory()->basico()->create();

        return Subscription::factory()->forTenant($tenant)->withPlan($plan)->create(array_merge([
            'status' => $status,
            'amount_cents' => 2900,
            'currency' => 'USD',
            'card_token' => 'tok_fake_card',
            'gateway_subscription_id' => 'ref-'.uniqid(),
        ], $attributes));
    }

    // ── process-recurring-charges ────────────────────────────────────────────

    public function test_recurring_charge_success_renews_the_subscription(): void
    {
        $sub = $this->subscription('active', ['next_billing_at' => now()->subDay()]);

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $sub->refresh();
        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->next_billing_at->isFuture());
        $this->assertNotNull($sub->last_paid_at);
        $this->assertSame([2900], $this->gateway()->chargedAmounts);
    }

    public function test_recurring_charge_decline_moves_to_past_due_and_schedules_retry(): void
    {
        $this->gateway()->forceChargeFailure = true;
        $sub = $this->subscription('active', ['next_billing_at' => now()->subDay()]);

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $sub->refresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertNotNull($sub->past_due_since);
        $this->assertSame(1, $sub->retry_count);
        $this->assertNotNull($sub->next_retry_at);
    }

    public function test_maintenance_mode_skips_recurring_charges(): void
    {
        BillingMaintenance::enable();
        $sub = $this->subscription('active', ['next_billing_at' => now()->subDay()]);

        $this->artisan('billing:process-recurring-charges')->assertSuccessful();

        $this->assertSame([], $this->gateway()->chargedAmounts, 'No charge should run under maintenance.');
        $this->assertSame(now()->subDay()->toDateString(), $sub->refresh()->next_billing_at->toDateString());
    }

    // ── retry-dunning ────────────────────────────────────────────────────────

    public function test_dunning_retry_success_reactivates(): void
    {
        $sub = $this->subscription('past_due', [
            'retry_count' => 1,
            'next_retry_at' => now()->subHour(),
            'past_due_since' => now()->subDays(3),
        ]);

        $this->artisan('billing:retry-dunning')->assertSuccessful();

        $this->assertSame('active', $sub->refresh()->status);
    }

    public function test_dunning_retry_failure_reschedules_next_attempt(): void
    {
        $this->gateway()->forceChargeFailure = true;
        $sub = $this->subscription('past_due', [
            'retry_count' => 1,
            'next_retry_at' => now()->subHour(),
            'past_due_since' => now()->subDays(3),
        ]);

        $this->artisan('billing:retry-dunning')->assertSuccessful();

        $sub->refresh();
        $this->assertSame('past_due', $sub->status);
        $this->assertSame(2, $sub->retry_count);
        $this->assertTrue($sub->next_retry_at->isFuture());
    }

    // ── suspend-overdue ──────────────────────────────────────────────────────

    public function test_suspend_overdue_suspends_exhausted_subscriptions(): void
    {
        $sub = $this->subscription('past_due', [
            'retry_count' => 3,
            'past_due_since' => now()->subDays(20),
        ]);

        $this->artisan('billing:suspend-overdue')->assertSuccessful();

        $this->assertSame('suspended', $sub->refresh()->status);
    }

    public function test_suspend_overdue_leaves_subscriptions_with_retries_left(): void
    {
        $sub = $this->subscription('past_due', [
            'retry_count' => 1,
            'past_due_since' => now()->subDay(),
        ]);

        $this->artisan('billing:suspend-overdue')->assertSuccessful();

        $this->assertSame('past_due', $sub->refresh()->status);
    }

    // ── soft-delete-cancelled ────────────────────────────────────────────────

    public function test_soft_delete_moves_ended_cancelled_subscriptions(): void
    {
        $sub = $this->subscription('canceled', [
            'current_period_end' => now()->subDay(),
            'canceled_at' => now()->subDays(2),
        ]);

        $this->artisan('billing:soft-delete-cancelled')->assertSuccessful();

        $this->assertSame('soft_deleted', $sub->refresh()->status);
    }

    public function test_soft_delete_keeps_cancelled_still_in_grace(): void
    {
        $sub = $this->subscription('canceled', [
            'current_period_end' => now()->addDays(5),
            'canceled_at' => now(),
        ]);

        $this->artisan('billing:soft-delete-cancelled')->assertSuccessful();

        $this->assertSame('canceled', $sub->refresh()->status);
    }

    // ── hard-delete-old ──────────────────────────────────────────────────────

    public function test_hard_delete_purges_pii_from_old_soft_deleted(): void
    {
        $sub = $this->subscription('soft_deleted', ['card_last4' => '4242', 'card_brand' => 'visa']);
        $sub->forceFill(['updated_at' => now()->subDays(200)])->saveQuietly();

        $this->artisan('billing:hard-delete-old')->assertSuccessful();

        $sub->refresh();
        $this->assertSame('hard_deleted', $sub->status);
        $this->assertNull($sub->card_token);
        $this->assertNull($sub->card_last4);
        $this->assertNull($sub->card_brand);
    }

    public function test_hard_delete_leaves_recently_soft_deleted(): void
    {
        $sub = $this->subscription('soft_deleted');
        $sub->forceFill(['updated_at' => now()->subDays(10)])->saveQuietly();

        $this->artisan('billing:hard-delete-old')->assertSuccessful();

        $this->assertSame('soft_deleted', $sub->refresh()->status);
    }

    // ── reconcile-subscriptions ──────────────────────────────────────────────

    public function test_reconcile_flags_a_gateway_drift(): void
    {
        $this->gateway()->transactionStatus = 'VOIDED';
        $sub = $this->subscription('active');

        $this->artisan('billing:reconcile-subscriptions')->assertSuccessful();

        $this->assertDatabaseHas('reconciliation_discrepancies', [
            'subscription_id' => $sub->id,
            'resolved' => false,
        ]);
    }

    public function test_reconcile_is_quiet_when_gateway_agrees(): void
    {
        $this->subscription('active'); // FakeGateway default status is APPROVED

        $this->artisan('billing:reconcile-subscriptions')->assertSuccessful();

        $this->assertDatabaseCount('reconciliation_discrepancies', 0);
    }

    // ── send-trial-reminders ─────────────────────────────────────────────────

    public function test_trial_reminders_are_dispatched_once_and_marked(): void
    {
        Event::fake([TrialEndingSoon::class]);
        $sub = $this->subscription('trialing', [
            'trial_ends_at' => now()->addDays(2),
            'trial_reminder_sent_at' => null,
        ]);

        $this->artisan('billing:send-trial-reminders')->assertSuccessful();
        $this->assertNotNull($sub->refresh()->trial_reminder_sent_at);

        // A second run must not re-notify.
        $this->artisan('billing:send-trial-reminders')->assertSuccessful();

        Event::assertDispatchedTimes(TrialEndingSoon::class, 1);
    }

    public function test_trial_reminders_ignore_trials_outside_the_window(): void
    {
        Event::fake([TrialEndingSoon::class]);
        $this->subscription('trialing', ['trial_ends_at' => now()->addDays(10)]);

        $this->artisan('billing:send-trial-reminders')->assertSuccessful();

        Event::assertNotDispatched(TrialEndingSoon::class);
    }
}
