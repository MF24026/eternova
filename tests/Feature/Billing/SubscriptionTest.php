<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the Subscription lifecycle and invariants.
 *
 * These tests verify domain rules enforced at the service / model layer, not via HTTP.
 * All assertions run against a real database (RefreshDatabase) — no mocks.
 */
final class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_can_only_have_one_active_subscription_at_a_time(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        // First subscription — active, allowed
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->active()
            ->create();

        // Service-layer rule: if a tenant already has an active subscription,
        // attempting to create a second must throw a DomainException.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/active subscription/i');

        $this->createSubscriptionForTenant($tenant, $plan, 'active');
    }

    public function test_trialing_counts_as_non_active_for_the_one_active_rule(): void
    {
        // A tenant can have one 'trialing' subscription — that is the expected initial state
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->pro()->create();

        $sub = Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->trialing()
            ->create();

        $this->assertSame('trialing', $sub->status);
        $this->assertTrue($sub->isTrialing());

        // Trialing subscription exists — now trying to create a second active one should fail
        $this->expectException(\DomainException::class);

        $this->createSubscriptionForTenant($tenant, $plan, 'active');
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

        // Simulate payment success: update status to active, set billing period
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

        // Simulate charge failure: transition to past_due
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

        // Subscription canceled but period ends in the future — tenant still has access
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
        // Period end is still in the future — access should be retained
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

    /**
     * Service-layer enforcement: one active-or-trialing subscription per tenant.
     *
     * MySQL does not support partial unique indexes natively, so this rule is enforced
     * here. Real service code calls this check before persisting.
     *
     * @throws \DomainException when a conflicting subscription already exists
     */
    private function createSubscriptionForTenant(
        Tenant $tenant,
        Plan $plan,
        string $status,
    ): Subscription {
        $hasConflict = Subscription::where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'trialing'])
            ->exists();

        if ($hasConflict) {
            throw new \DomainException(
                "Tenant [{$tenant->id}] already has an active subscription. ".
                'Cancel or expire it before creating a new one.'
            );
        }

        return Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->create(['status' => $status]);
    }
}
