<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Verifies that /me exposes the current tenant's plan entitlements (S9-E3),
 * which the frontend plan-gating UI consumes.
 */
final class MePlanTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    public function test_me_includes_the_current_tenants_plan_limits(): void
    {
        $plan = Plan::factory()->create([
            'slug'   => 'pro',
            'name'   => 'Pro',
            'limits' => ['pdf_quotations' => true, 'custom_domain' => false, 'max_branches' => 3],
        ]);

        $tenant = Tenant::factory()->create();
        Subscription::factory()->active()->create([
            'tenant_id' => $tenant->id,
            'plan_id'   => $plan->id,
        ]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('data.plan.slug', 'pro')
            ->assertJsonPath('data.plan.limits.pdf_quotations', true)
            ->assertJsonPath('data.plan.limits.custom_domain', false)
            ->assertJsonPath('data.plan.limits.max_branches', 3);
    }

    public function test_me_plan_is_null_without_an_active_subscription(): void
    {
        $tenant = Tenant::factory()->create();
        // Canceled subscription must NOT count as the current plan.
        $plan = Plan::factory()->create(['slug' => 'pro']);
        Subscription::factory()->canceled()->create([
            'tenant_id' => $tenant->id,
            'plan_id'   => $plan->id,
        ]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();
        app()->instance('currentTenant', $tenant);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/me');

        $response->assertOk()->assertJsonPath('data.plan', null);
    }

    public function test_plan_is_isolated_per_tenant(): void
    {
        $proPlan = Plan::factory()->create(['slug' => 'pro', 'limits' => ['custom_domain' => false]]);
        $entPlan = Plan::factory()->create(['slug' => 'enterprise', 'limits' => ['custom_domain' => true]]);

        $tenantA = Tenant::factory()->create();
        Subscription::factory()->active()->create(['tenant_id' => $tenantA->id, 'plan_id' => $proPlan->id]);
        $ownerA = User::factory()->forTenant($tenantA, role: 'owner')->create();

        $tenantB = Tenant::factory()->create();
        Subscription::factory()->active()->create(['tenant_id' => $tenantB->id, 'plan_id' => $entPlan->id]);
        $ownerB = User::factory()->forTenant($tenantB, role: 'owner')->create();

        app()->instance('currentTenant', $tenantA);
        $this->tenantGetJson($tenantA, $ownerA, '/api/v1/me')
            ->assertJsonPath('data.plan.slug', 'pro')
            ->assertJsonPath('data.plan.limits.custom_domain', false);

        app()->instance('currentTenant', $tenantB);
        $this->tenantGetJson($tenantB, $ownerB, '/api/v1/me')
            ->assertJsonPath('data.plan.slug', 'enterprise')
            ->assertJsonPath('data.plan.limits.custom_domain', true);
    }
}
