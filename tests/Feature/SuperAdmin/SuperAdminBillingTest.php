<?php

declare(strict_types=1);

namespace Tests\Feature\SuperAdmin;

use App\Models\User;
use App\Modules\Billing\Models\BillingAuditLog;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SuperAdmin billing console (Phase 7a backend). Covers the hard super_admin gate, the
 * cross-tenant metrics, and the audited operator actions. Security-first: non-super-admins are
 * locked out, and every mutating action requires a reason and writes an append-only audit row
 * stamped with the operator id.
 */
final class SuperAdminBillingTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['is_super_admin' => true]);
    }

    private function tenantWithSubscription(string $status = 'active', int $amountCents = 2900): Tenant
    {
        $tenant = Tenant::factory()->create();
        $plan = Plan::factory()->create(); // default factory -> unique slug
        Subscription::factory()->forTenant($tenant)->withPlan($plan)->create([
            'status' => $status,
            'amount_cents' => $amountCents,
            'currency' => 'USD',
            'trial_ends_at' => $status === 'trialing' ? now()->addDays(5) : null,
        ]);

        return $tenant;
    }

    // ── gate ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/super-admin/billing/metrics')->assertStatus(401);
    }

    public function test_non_super_admin_is_forbidden(): void
    {
        $regular = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($regular)
            ->getJson('/api/v1/super-admin/billing/metrics')
            ->assertStatus(403);
    }

    public function test_super_admin_sees_metrics(): void
    {
        $this->tenantWithSubscription('active', 2900);
        $this->tenantWithSubscription('active', 1900);

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/super-admin/billing/metrics')
            ->assertOk()
            ->assertJsonStructure(['data' => ['mrr_cents', 'arpu_cents', 'total_tenants', 'counts_by_status', 'plan_distribution', 'churn_rate']])
            ->assertJsonPath('data.mrr_cents', 4800);
    }

    public function test_super_admin_lists_tenants_across_the_platform(): void
    {
        $this->tenantWithSubscription();
        $this->tenantWithSubscription();

        $this->actingAs($this->superAdmin())
            ->getJson('/api/v1/super-admin/tenants')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── actions: accountability ──────────────────────────────────────────────

    public function test_extend_trial_requires_a_reason(): void
    {
        $tenant = $this->tenantWithSubscription('trialing');

        $this->actingAs($this->superAdmin())
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/extend-trial", ['days' => 7])
            ->assertStatus(422);
    }

    public function test_extend_trial_moves_the_date_and_audits_with_operator_and_reason(): void
    {
        $tenant = $this->tenantWithSubscription('trialing');
        $operator = $this->superAdmin();
        $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $before = $sub->trial_ends_at;

        $this->actingAs($operator)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/extend-trial", ['days' => 7, 'reason' => 'Customer goodwill extension'])
            ->assertOk();

        $this->assertTrue($sub->fresh()->trial_ends_at->gt($before));

        $entry = BillingAuditLog::where('event_type', 'superadmin.trial_extended')->firstOrFail();
        $this->assertSame($operator->id, $entry->payload['operator_id']);
        $this->assertSame('Customer goodwill extension', $entry->payload['reason']);
        $this->assertSame(7, $entry->payload['days']);
    }

    public function test_super_admin_can_suspend_an_active_tenant(): void
    {
        $tenant = $this->tenantWithSubscription('active');
        $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/suspend", ['reason' => 'Fraud review hold'])
            ->assertOk();

        $this->assertSame('suspended', $sub->fresh()->status);
        $this->assertDatabaseHas('billing_audit_log', ['event_type' => 'superadmin.suspended', 'subscription_id' => $sub->id]);
    }

    public function test_super_admin_can_reactivate_a_suspended_tenant(): void
    {
        $tenant = $this->tenantWithSubscription('suspended');
        $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($this->superAdmin())
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/reactivate", ['reason' => 'Dispute resolved'])
            ->assertOk();

        $this->assertSame('active', $sub->fresh()->status);
    }

    public function test_non_super_admin_cannot_perform_actions(): void
    {
        $tenant = $this->tenantWithSubscription('active');
        $regular = User::factory()->create(['is_super_admin' => false]);

        $this->actingAs($regular)
            ->postJson("/api/v1/super-admin/tenants/{$tenant->id}/suspend", ['reason' => 'should not work'])
            ->assertStatus(403);

        $this->assertSame('active', Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail()->status);
    }
}
