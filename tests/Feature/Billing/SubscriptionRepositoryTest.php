<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\SubscriptionStatus;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Repositories\SubscriptionRepository;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The repository feeds the billing crons, so it queries ACROSS tenants. Each subscription
 * here gets its own tenant to respect the one-active-per-tenant invariant.
 */
final class SubscriptionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionRepository $repository;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new SubscriptionRepository;
        // One shared plan: its slug is UNIQUE, and plan identity is irrelevant to the
        // cross-tenant queries under test. Each subscription still gets its own tenant.
        $this->plan = Plan::factory()->basico()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function subscription(SubscriptionStatus $status, array $attributes = []): Subscription
    {
        $tenant = Tenant::factory()->create();

        return Subscription::factory()
            ->forTenant($tenant)
            ->withPlan($this->plan)
            ->create(array_merge(['status' => $status->value], $attributes));
    }

    public function test_in_state_returns_only_subs_in_that_state(): void
    {
        $suspendedA = $this->subscription(SubscriptionStatus::Suspended);
        $suspendedB = $this->subscription(SubscriptionStatus::Suspended);
        $this->subscription(SubscriptionStatus::Active);

        $result = $this->repository->inState(SubscriptionStatus::Suspended);

        $this->assertEqualsCanonicalizing(
            [$suspendedA->id, $suspendedB->id],
            $result->pluck('id')->all()
        );
    }

    public function test_in_state_honours_the_older_than_cutoff(): void
    {
        $stale = $this->subscription(SubscriptionStatus::Canceled);
        $stale->forceFill(['updated_at' => now()->subDays(40)])->saveQuietly();

        $recent = $this->subscription(SubscriptionStatus::Canceled);
        $recent->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

        $result = $this->repository->inState(SubscriptionStatus::Canceled, now()->subDays(30));

        $this->assertSame([$stale->id], $result->pluck('id')->all());
    }
}
