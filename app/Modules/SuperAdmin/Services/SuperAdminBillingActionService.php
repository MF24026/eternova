<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Models\User;
use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\TenantBillingService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Manual SaaS-operator billing actions. Every method is accountable: it re-checks super-admin
 * (defense in depth — the middleware is the first gate, this is the second), requires a non-empty
 * $reason, and writes an append-only billing_audit_log entry stamped with the operator id and the
 * reason. State changes additionally go through the state machine, which writes its own audit row.
 */
final class SuperAdminBillingActionService
{
    public function __construct(private readonly TenantBillingService $billing) {}

    public function extendTrial(Tenant $tenant, int $days, User $operator, string $reason): Subscription
    {
        $subscription = $this->guardedSubscription($tenant, $operator, $reason);

        $base = $subscription->trial_ends_at ?? now();
        $subscription->forceFill(['trial_ends_at' => $base->copy()->addDays($days)])->save();

        $this->audit($tenant, $subscription->id, 'superadmin.trial_extended', $operator, $reason, ['days' => $days]);

        return $subscription;
    }

    public function suspend(Tenant $tenant, User $operator, string $reason): Subscription
    {
        $subscription = $this->guardedSubscription($tenant, $operator, $reason);

        if (! $subscription->state()->canTransitionTo(SubscriptionStatus::Suspended)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot suspend a subscription in state '{$subscription->status}'."],
            ]);
        }

        $subscription->state()->applyTransition(SubscriptionStatus::Suspended);
        $this->audit($tenant, $subscription->id, 'superadmin.suspended', $operator, $reason);

        return $subscription->refresh();
    }

    public function reactivate(Tenant $tenant, User $operator, string $reason): Subscription
    {
        $subscription = $this->guardedSubscription($tenant, $operator, $reason);

        if (! $subscription->state()->canTransitionTo(SubscriptionStatus::Active)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot reactivate a subscription in state '{$subscription->status}'."],
            ]);
        }

        $subscription->state()->applyTransition(SubscriptionStatus::Active);
        $this->audit($tenant, $subscription->id, 'superadmin.reactivated', $operator, $reason);

        return $subscription->refresh();
    }

    private function guardedSubscription(Tenant $tenant, User $operator, string $reason): Subscription
    {
        if (! $operator->is_super_admin) {
            throw new AccessDeniedHttpException('Super-admin access required.');
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['A reason is required for this action.']]);
        }

        $subscription = $this->billing->current($tenant->id);
        abort_if($subscription === null, 404, 'Tenant has no active subscription.'); // 404 renders correctly

        return $subscription;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function audit(Tenant $tenant, int $subscriptionId, string $event, User $operator, string $reason, array $extra = []): void
    {
        BillingAuditLog::create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscriptionId,
            'event_type' => $event,
            'payload' => array_merge(['operator_id' => $operator->id, 'reason' => $reason], $extra),
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }
}
