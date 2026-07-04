<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Gateways\Contracts\PaymentGatewayInterface;
use App\Modules\Billing\Gateways\FakeGateway;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

final class BillingSubscribeTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    public function test_owner_subscribe_creates_link_and_stores_affiliation_url(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create(['price_monthly_cents' => 2900]);
        Subscription::factory()->forTenant($tenant)->withPlan($plan)->create(['status' => 'trialing']);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/subscribe'), ['plan_id' => $plan->id])
            ->assertOk()
            ->assertJsonStructure(['data' => ['affiliation_url', 'status']]);

        $sub = Subscription::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
        $this->assertNotNull($sub->gateway_subscription_id);
        $this->assertNotNull($sub->affiliation_url);
    }

    public function test_changing_plan_cancels_the_previous_recurring_link(): void
    {
        $fake = new FakeGateway;
        $this->app->instance(PaymentGatewayInterface::class, $fake);

        $tenant = Tenant::factory()->create();
        $planA = Plan::factory()->create(['price_monthly_cents' => 900]);
        $planB = Plan::factory()->create(['price_monthly_cents' => 2900]);
        Subscription::factory()->forTenant($tenant)->withPlan($planA)->create(['status' => 'active']);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        // Subscribe to plan A -> stores link A.
        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/subscribe'), ['plan_id' => $planA->id])
            ->assertOk();
        $linkA = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail()->gateway_subscription_id;
        $this->assertNotNull($linkA);

        // Change to plan B -> stores link B and cancels link A so Wompi stops charging the old plan.
        $this->actingAs($owner)
            ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/subscribe'), ['plan_id' => $planB->id])
            ->assertOk();

        $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame($planB->id, $sub->plan_id);
        $this->assertSame(2900, $sub->amount_cents);
        $this->assertNotSame($linkA, $sub->gateway_subscription_id);
        $this->assertContains($linkA, $fake->cancelledLinks);
    }

    public function test_non_owner_cannot_subscribe(): void
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();

        $this->actingAs($staff)
            ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/subscribe'), ['plan_id' => $plan->id])
            ->assertStatus(403);
    }
}
