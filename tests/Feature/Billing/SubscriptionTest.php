<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\SubscriptionService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the Subscription lifecycle and invariants.
 *
 * All assertions run against a real database (RefreshDatabase) — no mocks.
 * The one-active-per-tenant invariant is covered twice:
 *   - via SubscriptionObserver (direct model create/update path)
 *   - via SubscriptionService (orchestration path)
 */
final class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────
    // Observer invariant: one active-or-trialing per tenant
    // ──────────────────────────────────────────────────────────────────

    public function test_observer_blocks_direct_subscription_create_when_another_is_active(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        // First active subscription — allowed.
        Subscription::factory()->forTenant($tenant)->withPlan($plan)->active()->create();

        // Second active subscription via direct model create — Observer must block it.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/active or trialing subscription/i');

        Subscription::factory()->forTenant($tenant)->withPlan($plan)->active()->create();
    }

    public function test_observer_blocks_update_to_active_when_another_is_active(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        Subscription::factory()->forTenant($tenant)->withPlan($plan)->active()->create();

        // A second subscription in canceled state — allowed at create time.
        $canceled = Subscription::factory()->forTenant($tenant)->withPlan($plan)->canceled()->create();

        // Attempting to flip the canceled sub to active must throw.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/active or trialing subscription/i');

        $canceled->update(['status' => 'active']);
    }

    public function test_observer_allows_canceled_to_remain_after_new_active(): void
    {
        $tenant = Tenant::factory()->create();
        $oldPlan = Plan::factory()->basico()->create();
        $newPlan = Plan::factory()->pro()->create();

        // Old subscription, fully canceled.
        $canceled = Subscription::factory()->forTenant($tenant)->withPlan($oldPlan)->canceled()->create();

        // New active subscription on a different plan — must succeed.
        $active = Subscription::factory()->forTenant($tenant)->withPlan($newPlan)->active()->create();

        $this->assertSame('canceled', $canceled->status);
        $this->assertSame('active', $active->status);
        $this->assertDatabaseCount('subscriptions', 2);
    }

    // ──────────────────────────────────────────────────────────────────
    // Existing lifecycle tests (original suite — unchanged behaviour)
    // ──────────────────────────────────────────────────────────────────

    public function test_tenant_can_only_have_one_active_subscription_at_a_time(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        Subscription::factory()->forTenant($tenant)->withPlan($plan)->active()->create();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/active or trialing subscription/i');

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $service->create($tenant, $plan, ['status' => 'active']);
    }

    public function test_trialing_counts_as_non_active_for_the_one_active_rule(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->pro()->create();

        $sub = Subscription::factory()->forTenant($tenant)->withPlan($plan)->trialing()->create();

        $this->assertSame('trialing', $sub->status);
        $this->assertTrue($sub->isTrialing());

        // Trialing subscription exists — creating a second active one must throw.
        $this->expectException(\DomainException::class);

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $service->create($tenant, $plan, ['status' => 'active']);
    }

    public function test_subscription_transitions_from_trialing_to_active_when_first_payment_succeeds(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->pro()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->trialing()
            ->create();

        $this->assertSame('trialing', $subscription->status);
        $this->assertTrue($subscription->isTrialing());

        // Simulate payment success: update status to active, set billing period.
        $subscription->update([
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);

        $subscription->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->isActive());
        $this->assertFalse($subscription->isTrialing());
        $this->assertNotNull($subscription->current_period_end);
    }

    public function test_subscription_transitions_to_past_due_when_payment_fails(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->active()
            ->create();

        $this->assertSame('active', $subscription->status);

        // Simulate charge failure: transition to past_due.
        $subscription->update(['status' => 'past_due']);

        $subscription->refresh();

        $this->assertSame('past_due', $subscription->status);
        $this->assertTrue($subscription->isPastDue());
        $this->assertFalse($subscription->isActive());
    }

    public function test_canceled_subscription_remains_on_grace_period_when_cancel_at_period_end_is_true(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->pro()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->create([
                'status' => 'canceled',
                'canceled_at' => now(),
                'cancel_at_period_end' => true,
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->addDays(15),
            ]);

        $this->assertTrue($subscription->isCanceled());
        $this->assertTrue($subscription->isOnGracePeriod());
        $this->assertTrue($subscription->current_period_end->isFuture());
    }

    public function test_canceled_subscription_not_on_grace_period_when_period_has_ended(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->create([
                'status' => 'canceled',
                'canceled_at' => now()->subDays(35),
                'cancel_at_period_end' => false,
                'current_period_start' => now()->subMonth()->startOfMonth(),
                'current_period_end' => now()->subDays(5),
            ]);

        $this->assertTrue($subscription->isCanceled());
        $this->assertFalse($subscription->isOnGracePeriod());
    }

    public function test_invoice_total_is_subtotal_plus_tax(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->pro()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->active()
            ->create();

        $subtotal = 2900;
        $tax = 377; // ~13% tax
        $total = $subtotal + $tax;

        $invoice = Invoice::factory()
            ->forSubscription($subscription)
            ->create([
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => $total,
                'status' => 'open',
            ]);

        $this->assertSame($subtotal, $invoice->subtotal_cents);
        $this->assertSame($tax, $invoice->tax_cents);
        $this->assertSame($total, $invoice->total_cents);
        $this->assertSame($subtotal + $tax, $invoice->total_cents);
        $this->assertTrue($invoice->hasTotalsConsistency());
    }

    public function test_invoice_total_with_zero_tax_equals_subtotal(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->active()
            ->create();

        $invoice = Invoice::factory()
            ->forSubscription($subscription)
            ->create([
                'subtotal_cents' => 900,
                'tax_cents' => 0,
                'total_cents' => 900,
                'status' => 'open',
            ]);

        $this->assertSame(0, $invoice->tax_cents);
        $this->assertSame(900, $invoice->total_cents);
        $this->assertTrue($invoice->hasTotalsConsistency());
    }

    public function test_days_until_trial_ends_returns_positive_integer_during_trial(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->create([
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays(10),
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(14),
            ]);

        $days = $subscription->daysUntilTrialEnds();

        $this->assertGreaterThan(0, $days);
        $this->assertLessThanOrEqual(10, $days);
    }

    public function test_days_until_trial_ends_returns_zero_when_trial_already_ended(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        $subscription = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->create([
                'status' => 'past_due',
                'trial_ends_at' => now()->subDays(3),
                'current_period_start' => now()->subMonth(),
                'current_period_end' => now()->subDays(3),
            ]);

        $this->assertSame(0, $subscription->daysUntilTrialEnds());
    }

    // ──────────────────────────────────────────────────────────────────
    // SubscriptionService tests
    // ──────────────────────────────────────────────────────────────────

    public function test_subscription_service_create_uses_trialing_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $sub = $service->create($tenant, $plan);

        $this->assertSame('trialing', $sub->status);
        $this->assertNotNull($sub->trial_ends_at);
        // trial_ends_at should be approximately 30 days from now (allow ±1 min clock drift).
        $this->assertEqualsWithDelta(
            now()->addDays(30)->timestamp,
            $sub->trial_ends_at->timestamp,
            60
        );
    }

    public function test_subscription_service_activate_transitions_correctly(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->pro()->create();

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $sub = $service->create($tenant, $plan);

        $this->assertSame('trialing', $sub->status);

        $service->activate($sub);
        $sub->refresh();

        $this->assertSame('active', $sub->status);
        $this->assertNull($sub->trial_ends_at);
    }

    public function test_subscription_service_activate_throws_when_status_is_not_trialing_or_past_due(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        $sub = Subscription::factory()->forTenant($tenant)->withPlan($plan)->active()->create();

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/cannot activate/i');

        $service->activate($sub);
    }

    public function test_subscription_service_cancel_at_period_end_keeps_status_active_but_flags(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->pro()->create();

        $sub = Subscription::factory()->forTenant($tenant)->withPlan($plan)->active()->create();

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $service->cancel($sub, atPeriodEnd: true);
        $sub->refresh();

        $this->assertSame('active', $sub->status);
        $this->assertTrue($sub->cancel_at_period_end);
        $this->assertNull($sub->canceled_at);
    }

    public function test_subscription_service_cancel_immediate_changes_status_and_sets_canceled_at(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        $sub = Subscription::factory()->forTenant($tenant)->withPlan($plan)->active()->create();

        /** @var SubscriptionService $service */
        $service = app(SubscriptionService::class);
        $service->cancel($sub, atPeriodEnd: false);
        $sub->refresh();

        $this->assertSame('canceled', $sub->status);
        $this->assertNotNull($sub->canceled_at);
    }
}
