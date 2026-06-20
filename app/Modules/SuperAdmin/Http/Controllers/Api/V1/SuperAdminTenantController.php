<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Cross-tenant read views for the operator console. Super-admin only. Subscriptions are
 * eager-loaded (the app forbids lazy loading outside production) and the live one is picked from
 * the loaded collection — no N+1.
 */
final class SuperAdminTenantController extends Controller
{
    /** Non-terminal states that represent a tenant's "current" subscription. */
    private const LIVE_STATES = ['trialing', 'active', 'past_due', 'paused', 'suspended'];

    public function index(): JsonResponse
    {
        $tenants = Tenant::query()
            ->with(['subscriptions' => fn ($q) => $q->with('plan:id,name,slug')])
            ->orderBy('name')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $tenants->map(fn (Tenant $tenant): array => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'subscription' => $this->currentSubscriptionSummary($tenant),
            ])->all(),
        ]);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->load(['subscriptions' => fn ($q) => $q->with('plan:id,name,slug')]);

        $auditLog = BillingAuditLog::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->limit(50)
            ->get(['id', 'event_type', 'payload', 'occurred_at']);

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'email' => $tenant->email,
                'subscription' => $this->currentSubscriptionSummary($tenant),
                'audit_log' => $auditLog,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentSubscriptionSummary(Tenant $tenant): ?array
    {
        $subscription = $tenant->subscriptions
            ->whereIn('status', self::LIVE_STATES)
            ->sortByDesc('id')
            ->first();

        if (! $subscription instanceof Subscription) {
            return null;
        }

        return [
            'id' => $subscription->id,
            'status' => $subscription->status,
            'plan' => $subscription->plan?->name,
            'amount_cents' => $subscription->amount_cents,
            'currency' => $subscription->currency,
            'trial_ends_at' => $subscription->trial_ends_at,
            'current_period_end' => $subscription->current_period_end,
        ];
    }
}
