<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * /api/v1/account/billing/* — Owner-only tenant billing surface. Covers the gate (owner vs
 * the rest), cross-tenant isolation, and the cancel/change-plan/download actions.
 *
 * Backend API only (Phase 6a). The Vue UI + Playwright E2E + qa-engineer visual QA land in
 * the Phase 6b follow-up.
 */
final class BillingAccountApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private function tenantWithSubscription(string $status = 'active'): Tenant
    {
        $tenant = Tenant::factory()->create();
        // Default factory (unique slug) — these tests create two tenants, and the fixed
        // 'basico' slug is UNIQUE, so reusing the named state would collide.
        $plan = Plan::factory()->create();
        Subscription::factory()->forTenant($tenant)->withPlan($plan)->create([
            'status' => $status,
            'amount_cents' => 900,
            'currency' => 'USD',
        ]);

        return $tenant;
    }

    private function owner(Tenant $tenant): User
    {
        return User::factory()->forTenant($tenant, role: 'owner')->create();
    }

    // ── gate ─────────────────────────────────────────────────────────────────

    public function test_owner_sees_billing_overview(): void
    {
        $tenant = $this->tenantWithSubscription();

        $this->actingAs($this->owner($tenant))
            ->getJson($this->tenantUrl($tenant, 'api/v1/account/billing'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['subscription' => ['id', 'status', 'plan'], 'recent_invoices']]);
    }

    public function test_admin_staff_and_customer_are_forbidden(): void
    {
        $tenant = $this->tenantWithSubscription();

        foreach (['admin', 'staff', 'customer'] as $role) {
            $user = User::factory()->forTenant($tenant, role: $role)->create();

            $this->actingAs($user)
                ->getJson($this->tenantUrl($tenant, 'api/v1/account/billing'))
                ->assertStatus(403);
        }
    }

    public function test_owner_of_another_tenant_is_forbidden(): void
    {
        $tenantA = $this->tenantWithSubscription();
        $tenantB = $this->tenantWithSubscription();
        $ownerA = $this->owner($tenantA);

        // ownerA is a perfectly valid owner — but of tenant A, not B.
        $this->actingAs($ownerA)
            ->getJson($this->tenantUrl($tenantB, 'api/v1/account/billing'))
            ->assertStatus(403);
    }

    // ── invoices ─────────────────────────────────────────────────────────────

    public function test_invoice_list_is_scoped_to_the_tenant(): void
    {
        $tenantA = $this->tenantWithSubscription();
        $tenantB = $this->tenantWithSubscription();
        $subA = Subscription::query()->where('tenant_id', $tenantA->id)->firstOrFail();
        $subB = Subscription::query()->where('tenant_id', $tenantB->id)->firstOrFail();
        Invoice::factory()->forSubscription($subA)->create(['number' => 'INV-A-1']);
        Invoice::factory()->forSubscription($subB)->create(['number' => 'INV-B-1']);

        $this->actingAs($this->owner($tenantA))
            ->getJson($this->tenantUrl($tenantA, 'api/v1/account/billing/invoices'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['number' => 'INV-A-1'])
            ->assertJsonMissing(['number' => 'INV-B-1']);
    }

    public function test_owner_can_download_own_invoice_pdf(): void
    {
        Storage::fake();
        $tenant = $this->tenantWithSubscription();
        $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();
        Storage::put('invoices/'.$tenant->id.'/INV-1.pdf', '%PDF-FAKE');
        $invoice = Invoice::factory()->forSubscription($sub)->create([
            'number' => 'INV-1', 'pdf_url' => 'invoices/'.$tenant->id.'/INV-1.pdf',
        ]);

        $this->actingAs($this->owner($tenant))
            ->get($this->tenantUrl($tenant, "api/v1/account/billing/invoices/{$invoice->id}/download"))
            ->assertOk();
    }

    public function test_cannot_download_another_tenants_invoice(): void
    {
        $tenantA = $this->tenantWithSubscription();
        $tenantB = $this->tenantWithSubscription();
        $subB = Subscription::query()->where('tenant_id', $tenantB->id)->firstOrFail();
        $invoiceB = Invoice::factory()->forSubscription($subB)->create(['number' => 'INV-B-9']);

        // ownerA asks for invoice B's id through tenant A's host → 404 (never leaks B).
        $this->actingAs($this->owner($tenantA))
            ->get($this->tenantUrl($tenantA, "api/v1/account/billing/invoices/{$invoiceB->id}/download"))
            ->assertStatus(404);
    }

    // ── actions ──────────────────────────────────────────────────────────────

    public function test_owner_can_cancel_at_period_end(): void
    {
        $tenant = $this->tenantWithSubscription();
        $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($this->owner($tenant))
            ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/cancel'))
            ->assertOk();

        $this->assertTrue((bool) $sub->fresh()->cancel_at_period_end);
        $this->assertSame('active', $sub->fresh()->status, 'Cancel-at-period-end keeps access.');
    }

    public function test_owner_can_change_plan(): void
    {
        $tenant = $this->tenantWithSubscription();
        $pro = Plan::factory()->pro()->create();
        $sub = Subscription::query()->where('tenant_id', $tenant->id)->firstOrFail();

        $this->actingAs($this->owner($tenant))
            ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/plan'), ['plan_id' => $pro->id])
            ->assertOk();

        $sub->refresh();
        $this->assertSame($pro->id, $sub->plan_id);
        $this->assertSame($pro->price_monthly_cents, $sub->amount_cents);
    }

    public function test_change_plan_rejects_unknown_plan(): void
    {
        $tenant = $this->tenantWithSubscription();

        $this->actingAs($this->owner($tenant))
            ->postJson($this->tenantUrl($tenant, 'api/v1/account/billing/plan'), ['plan_id' => 999999])
            ->assertStatus(422);
    }
}
