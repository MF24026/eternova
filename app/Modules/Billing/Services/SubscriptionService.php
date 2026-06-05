<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates subscription lifecycle transitions.
 *
 * Controllers and Jobs call this Service — never the Subscription model directly.
 * The SubscriptionObserver still enforces the one-active-per-tenant invariant as
 * a second line of defence, so callers that bypass this Service (tinker, seeders)
 * are still protected.
 */
final readonly class SubscriptionService
{
    /** @var list<string> */
    private const ACTIVATABLE_STATUSES = ['trialing', 'past_due'];

    /**
     * Create a new subscription for a tenant on a given plan.
     *
     * Defaults to trialing status with a 30-day trial window.
     * Pass $attributes to override any default (e.g. status = 'active' for a paid plan
     * when the tenant already completed checkout before the trial).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws \DomainException (via SubscriptionObserver) when tenant already has an active/trialing sub
     */
    public function create(Tenant $tenant, Plan $plan, array $attributes = []): Subscription
    {
        return DB::transaction(function () use ($tenant, $plan, $attributes): Subscription {
            $defaults = [
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'trial_ends_at' => now()->addDays(30),
                'current_period_start' => now(),
                'current_period_end' => now()->addDays(30),
                'cancel_at_period_end' => false,
                'canceled_at' => null,
            ];

            return Subscription::create(array_merge($defaults, $attributes));
        });
    }

    /**
     * Transition a subscription from trialing (or past_due) to active.
     *
     * Called when Wompi confirms the first (or retry) payment. Clears trial_ends_at
     * and sets the billing period to the given $periodEnd (defaults to 30 days out).
     *
     * @throws \DomainException when the subscription is not in a transitionable status
     */
    public function activate(Subscription $sub, ?Carbon $periodEnd = null): void
    {
        if (! in_array($sub->status, self::ACTIVATABLE_STATUSES, strict: true)) {
            throw new \DomainException(
                "Cannot activate subscription [{$sub->id}]: current status is '{$sub->status}'. ".
                'Only trialing or past_due subscriptions can be activated.'
            );
        }

        $periodEnd ??= now()->addDays(30);

        $sub->update([
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_start' => now(),
            'current_period_end' => $periodEnd,
        ]);
    }

    /**
     * Transition an active subscription to past_due after a failed charge.
     *
     * Called by the Wompi webhook handler when a payment attempt is declined.
     *
     * @throws \DomainException when the subscription is not active
     */
    public function markPastDue(Subscription $sub): void
    {
        if (! $sub->isActive()) {
            throw new \DomainException(
                "Cannot mark subscription [{$sub->id}] as past_due: current status is '{$sub->status}'. ".
                'Only active subscriptions can become past_due.'
            );
        }

        $sub->update(['status' => 'past_due']);
    }

    /**
     * Cancel a subscription.
     *
     * When $atPeriodEnd is true (the default): sets cancel_at_period_end = true but
     * leaves the status as-is so the tenant retains access until current_period_end.
     *
     * When $atPeriodEnd is false: immediately sets status = 'canceled' and records
     * canceled_at. Use for refund + immediate revocation flows.
     */
    public function cancel(Subscription $sub, bool $atPeriodEnd = true): void
    {
        if ($atPeriodEnd) {
            $sub->update(['cancel_at_period_end' => true]);

            return;
        }

        $sub->update([
            'status' => 'canceled',
            'canceled_at' => now(),
        ]);
    }
}
