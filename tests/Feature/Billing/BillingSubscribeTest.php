<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
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
