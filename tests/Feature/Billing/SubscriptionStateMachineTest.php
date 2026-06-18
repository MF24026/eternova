<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Events\SubscriptionStateChanged;
use App\Modules\Billing\Domain\Exceptions\InvalidSubscriptionTransitionException;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Exercises the 9-state subscription State pattern: valid moves, rejected moves, the
 * persistence + audit side-effects of applyTransition(), and each state's capability flags.
 *
 * Pure domain logic with no UI surface, so this layer is PHPUnit-only per the dual-layer
 * doctrine (Playwright comes in with the Phase 6 billing UI).
 */
final class SubscriptionStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function subscriptionInState(SubscriptionStatus $status): Subscription
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->basico()->create();

        return Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($plan)
            ->create(['status' => $status->value]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Valid transitions
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return list<array{SubscriptionStatus, SubscriptionStatus}>
     */
    public static function validTransitions(): array
    {
        return [
            'trial -> active' => [SubscriptionStatus::Trialing, SubscriptionStatus::Active],
            'trial -> expired' => [SubscriptionStatus::Trialing, SubscriptionStatus::Expired],
            'trial -> canceled' => [SubscriptionStatus::Trialing, SubscriptionStatus::Canceled],
            'active -> past_due' => [SubscriptionStatus::Active, SubscriptionStatus::PastDue],
            'active -> paused' => [SubscriptionStatus::Active, SubscriptionStatus::Paused],
            'active -> canceled' => [SubscriptionStatus::Active, SubscriptionStatus::Canceled],
            'past_due -> active' => [SubscriptionStatus::PastDue, SubscriptionStatus::Active],
            'past_due -> suspended' => [SubscriptionStatus::PastDue, SubscriptionStatus::Suspended],
            'paused -> active' => [SubscriptionStatus::Paused, SubscriptionStatus::Active],
            'suspended -> active' => [SubscriptionStatus::Suspended, SubscriptionStatus::Active],
            'suspended -> soft_deleted' => [SubscriptionStatus::Suspended, SubscriptionStatus::SoftDeleted],
            'canceled -> soft_deleted' => [SubscriptionStatus::Canceled, SubscriptionStatus::SoftDeleted],
            'expired -> soft_deleted' => [SubscriptionStatus::Expired, SubscriptionStatus::SoftDeleted],
            'soft_deleted -> hard_deleted' => [SubscriptionStatus::SoftDeleted, SubscriptionStatus::HardDeleted],
        ];
    }

    #[DataProvider('validTransitions')]
    public function test_valid_transition_is_applied(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        $sub = $this->subscriptionInState($from);

        $sub->state()->applyTransition($to);

        $this->assertSame($to->value, $sub->fresh()->status);
    }

    // ──────────────────────────────────────────────────────────────────
    // Invalid transitions
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return list<array{SubscriptionStatus, SubscriptionStatus}>
     */
    public static function invalidTransitions(): array
    {
        return [
            'active -> hard_deleted' => [SubscriptionStatus::Active, SubscriptionStatus::HardDeleted],
            'trial -> suspended' => [SubscriptionStatus::Trialing, SubscriptionStatus::Suspended],
            'soft_deleted -> past_due' => [SubscriptionStatus::SoftDeleted, SubscriptionStatus::PastDue],
            'hard_deleted -> active' => [SubscriptionStatus::HardDeleted, SubscriptionStatus::Active],
            'active -> active' => [SubscriptionStatus::Active, SubscriptionStatus::Active],
        ];
    }

    #[DataProvider('invalidTransitions')]
    public function test_invalid_transition_is_rejected(SubscriptionStatus $from, SubscriptionStatus $to): void
    {
        $sub = $this->subscriptionInState($from);

        $this->expectException(InvalidSubscriptionTransitionException::class);

        try {
            $sub->state()->applyTransition($to);
        } finally {
            // The status column must be untouched after a rejected transition.
            $this->assertSame($from->value, $sub->fresh()->status);
        }
    }

    public function test_hard_deleted_is_terminal(): void
    {
        $sub = $this->subscriptionInState(SubscriptionStatus::HardDeleted);

        $this->assertSame([], $sub->state()->allowedTransitions());
    }

    // ──────────────────────────────────────────────────────────────────
    // applyTransition side-effects: persistence, event, audit
    // ──────────────────────────────────────────────────────────────────

    public function test_apply_transition_writes_an_append_only_audit_row(): void
    {
        $sub = $this->subscriptionInState(SubscriptionStatus::Active);

        $sub->state()->applyTransition(SubscriptionStatus::PastDue);

        $this->assertDatabaseCount('billing_audit_log', 1);

        $log = BillingAuditLog::firstOrFail();
        $this->assertSame($sub->tenant_id, $log->tenant_id);
        $this->assertSame($sub->id, $log->subscription_id);
        $this->assertSame('subscription.state_changed', $log->event_type);
        $this->assertSame('active', $log->payload['from']);
        $this->assertSame('past_due', $log->payload['to']);
    }

    public function test_apply_transition_dispatches_state_changed_event(): void
    {
        Event::fake([SubscriptionStateChanged::class]);

        $sub = $this->subscriptionInState(SubscriptionStatus::Active);
        $sub->state()->applyTransition(SubscriptionStatus::Canceled, correlationId: 'corr-123');

        Event::assertDispatched(
            SubscriptionStateChanged::class,
            static function (SubscriptionStateChanged $event) use ($sub): bool {
                return $event->subscriptionId === $sub->id
                    && $event->from === SubscriptionStatus::Active
                    && $event->to === SubscriptionStatus::Canceled
                    && $event->correlationId === 'corr-123';
            }
        );
    }

    public function test_correlation_id_is_recorded_on_the_audit_row(): void
    {
        $sub = $this->subscriptionInState(SubscriptionStatus::Active);

        $sub->state()->applyTransition(SubscriptionStatus::PastDue, correlationId: 'trace-xyz');

        $this->assertSame('trace-xyz', BillingAuditLog::firstOrFail()->correlation_id);
    }

    public function test_apply_transition_auto_generates_a_correlation_id_when_omitted(): void
    {
        $sub = $this->subscriptionInState(SubscriptionStatus::Active);

        $sub->state()->applyTransition(SubscriptionStatus::PastDue);

        $this->assertNotEmpty(BillingAuditLog::firstOrFail()->correlation_id);
    }

    // ──────────────────────────────────────────────────────────────────
    // Capability flags
    // ──────────────────────────────────────────────────────────────────

    public function test_active_state_capabilities(): void
    {
        $state = $this->subscriptionInState(SubscriptionStatus::Active)->state();

        $this->assertTrue($state->canCharge());
        $this->assertTrue($state->canCancel());
        $this->assertTrue($state->canPause());
        $this->assertTrue($state->canRefund());
        $this->assertTrue($state->isAccessible());
        $this->assertFalse($state->isReadOnly());
    }

    public function test_suspended_state_is_accessible_but_read_only(): void
    {
        $state = $this->subscriptionInState(SubscriptionStatus::Suspended)->state();

        $this->assertTrue($state->isAccessible());
        $this->assertTrue($state->isReadOnly());
        $this->assertTrue($state->canCharge());   // back-payment restores access
        $this->assertFalse($state->canCancel());
    }

    public function test_hard_deleted_state_grants_nothing(): void
    {
        $state = $this->subscriptionInState(SubscriptionStatus::HardDeleted)->state();

        $this->assertFalse($state->canCharge());
        $this->assertFalse($state->canCancel());
        $this->assertFalse($state->canRefund());
        $this->assertFalse($state->isAccessible());
    }

    public function test_state_resolves_to_the_matching_class(): void
    {
        $sub = $this->subscriptionInState(SubscriptionStatus::Paused);

        $this->assertSame(SubscriptionStatus::Paused, $sub->state()->name());
        $this->assertTrue($sub->state()->canResume());
    }
}
