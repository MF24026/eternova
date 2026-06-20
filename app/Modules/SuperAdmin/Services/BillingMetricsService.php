<?php

declare(strict_types=1);

namespace App\Modules\SuperAdmin\Services;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Cross-tenant billing metrics for the SaaS operator console. Read-only aggregates over ALL
 * tenants — Subscription is not tenant-scoped, so these queries see everything by design.
 */
final class BillingMetricsService
{
    /**
     * Monthly Recurring Revenue in cents: active subscriptions, yearly plans normalized to /12.
     */
    public function mrrCents(): int
    {
        return (int) Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->get(['amount_cents', 'billing_period'])
            ->sum(fn (Subscription $s): int => $s->billing_period === 'yearly'
                ? intdiv((int) $s->amount_cents, 12)
                : (int) $s->amount_cents);
    }

    /** Average revenue per active account, in cents. */
    public function arpuCents(): int
    {
        $active = $this->countByStatus()[SubscriptionStatus::Active->value] ?? 0;

        return $active > 0 ? intdiv($this->mrrCents(), $active) : 0;
    }

    /**
     * @return array<string, int> status => count, across all tenants
     */
    public function countByStatus(): array
    {
        $counts = Subscription::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($n): int => (int) $n)
            ->all();

        // Always present every state, even at zero, so the dashboard shape is stable.
        $base = [];
        foreach (SubscriptionStatus::cases() as $case) {
            $base[$case->value] = 0;
        }

        return array_merge($base, $counts);
    }

    /**
     * @return array<string, int> plan name => count of active subscriptions
     */
    public function planDistribution(): array
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->with('plan:id,name')
            ->get(['plan_id'])
            ->groupBy(fn (Subscription $s): string => $s->plan?->name ?? 'unknown')
            ->map(fn ($group): int => $group->count())
            ->all();
    }

    public function totalTenants(): int
    {
        return Tenant::query()->count();
    }

    /**
     * Crude monthly churn: subscriptions canceled within the month over those active at its start.
     */
    public function churnRate(?CarbonInterface $month = null): float
    {
        $month ??= Carbon::now();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $canceled = Subscription::query()
            ->whereNotNull('canceled_at')
            ->whereBetween('canceled_at', [$start, $end])
            ->count();

        $activeAtStart = Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value])
            ->where('created_at', '<', $start)
            ->count();

        return $activeAtStart > 0 ? round($canceled / $activeAtStart, 4) : 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'mrr_cents' => $this->mrrCents(),
            'arpu_cents' => $this->arpuCents(),
            'total_tenants' => $this->totalTenants(),
            'counts_by_status' => $this->countByStatus(),
            'plan_distribution' => $this->planDistribution(),
            'churn_rate' => $this->churnRate(),
        ];
    }
}
