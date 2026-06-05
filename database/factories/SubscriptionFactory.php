<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ];
    }

    /**
     * Subscription in the trialing state (new signup, within free trial window).
     */
    public function trialing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'trialing',
            'trial_ends_at' => now()->addDays(14),
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(14),
        ]);
    }

    /**
     * Active paid subscription with current billing period.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
            'trial_ends_at' => null,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ]);
    }

    /**
     * Past-due subscription — last charge failed, currently in dunning.
     */
    public function pastDue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'past_due',
            'trial_ends_at' => null,
            'current_period_start' => now()->subMonth()->startOfMonth(),
            'current_period_end' => now()->subMonth()->endOfMonth(),
        ]);
    }

    /**
     * Canceled subscription. May still be on grace period if period_end is in the future.
     */
    public function canceled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'canceled',
            'canceled_at' => now()->subDay(),
            'cancel_at_period_end' => false,
        ]);
    }

    /**
     * Scope to a specific tenant.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Scope to a specific plan.
     */
    public function withPlan(Plan $plan): static
    {
        return $this->state(fn (array $attributes) => [
            'plan_id' => $plan->id,
        ]);
    }
}
