<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\States;

use App\Modules\Billing\Domain\Events\SubscriptionStateChanged;
use App\Modules\Billing\Domain\Exceptions\InvalidSubscriptionTransitionException;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use Illuminate\Support\Str;

/**
 * Base of the subscription State pattern. Each of the 9 states is a subclass that
 * answers capability questions (canCharge, isAccessible, ...) and declares which states
 * it may move to. The only sanctioned way to change a subscription's status is
 * applyTransition() — it validates the move, writes the column, and emits the domain
 * event that the audit log (and later, notifications) listen to.
 *
 * Direct `$subscription->update(['status' => ...])` still works mechanically (the column
 * is the source of truth) but bypasses validation + auditing. Domain code must go through
 * the state machine; the legacy SubscriptionService/Observer paths remain for the
 * one-active-per-tenant invariant they already guard.
 */
abstract class SubscriptionState
{
    final public function __construct(protected readonly Subscription $subscription) {}

    abstract public function name(): SubscriptionStatus;

    abstract public function canCharge(): bool;

    abstract public function canCancel(): bool;

    abstract public function canPause(): bool;

    abstract public function canResume(): bool;

    abstract public function canRefund(): bool;

    /** Can the tenant use the app at all in this state? */
    abstract public function isAccessible(): bool;

    /** Is the app accessible but read-only (suspended/paused dunning posture)? */
    abstract public function isReadOnly(): bool;

    /** @return list<SubscriptionStatus> states reachable from here */
    abstract public function allowedTransitions(): array;

    final public function canTransitionTo(SubscriptionStatus $next): bool
    {
        return in_array($next, $this->allowedTransitions(), strict: true);
    }

    /**
     * Move the subscription to $next, persist, and emit SubscriptionStateChanged.
     *
     * @throws InvalidSubscriptionTransitionException when $next is not reachable from here
     */
    final public function applyTransition(SubscriptionStatus $next, ?string $correlationId = null): void
    {
        if (! $this->canTransitionTo($next)) {
            throw InvalidSubscriptionTransitionException::between($this->name(), $next);
        }

        $from = $this->name();

        $this->subscription->status = $next->value;
        $this->subscription->save();

        SubscriptionStateChanged::dispatch(
            (string) $this->subscription->tenant_id,
            (int) $this->subscription->id,
            $from,
            $next,
            $correlationId ?? (string) Str::uuid(),
        );
    }

    /**
     * Resolve the State object for a subscription's current status column.
     */
    public static function for(Subscription $subscription): self
    {
        return match (SubscriptionStatus::from($subscription->status)) {
            SubscriptionStatus::Trialing => new TrialingState($subscription),
            SubscriptionStatus::Active => new ActiveState($subscription),
            SubscriptionStatus::PastDue => new PastDueState($subscription),
            SubscriptionStatus::Paused => new PausedState($subscription),
            SubscriptionStatus::Canceled => new CanceledState($subscription),
            SubscriptionStatus::Expired => new ExpiredState($subscription),
            SubscriptionStatus::Suspended => new SuspendedState($subscription),
            SubscriptionStatus::SoftDeleted => new SoftDeletedState($subscription),
            SubscriptionStatus::HardDeleted => new HardDeletedState($subscription),
        };
    }
}
