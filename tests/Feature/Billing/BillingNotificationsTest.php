<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Billing\Domain\Events\SubscriptionChargeFailed;
use App\Modules\Billing\Domain\Events\SubscriptionRenewed;
use App\Modules\Billing\Domain\Events\SubscriptionSuspended;
use App\Modules\Billing\Domain\Events\TrialEndingSoon;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Notifications\ChargeFailedNotification;
use App\Modules\Billing\Notifications\InvoiceReadyNotification;
use App\Modules\Billing\Notifications\SubscriptionSuspendedNotification;
use App\Modules\Billing\Notifications\TrialEndingNotification;
use App\Modules\Billing\Services\InvoicePdfService;
use App\Modules\Billing\Services\InvoiceService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 5: invoice issuance/PDF + the domain-event -> notification wiring. Notifications are
 * SaaS->tenant-owner. PHPUnit only (no UI), with Notification + Storage faked.
 */
final class BillingNotificationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Tenant, User}
     */
    private function tenantWithOwner(): array
    {
        $tenant = Tenant::factory()->create(['email' => 'owner@tenant.test']);
        $owner = User::factory()->create();
        $tenant->users()->attach($owner->id, ['role' => 'owner', 'joined_at' => now()]);

        return [$tenant, $owner];
    }

    private function subscription(Tenant $tenant, string $status = 'active'): Subscription
    {
        $plan = Plan::factory()->basico()->create();

        return Subscription::factory()->forTenant($tenant)->withPlan($plan)->create([
            'status' => $status,
            'amount_cents' => 2900,
            'currency' => 'USD',
            'gateway_subscription_id' => 'ref-'.uniqid(),
        ]);
    }

    // ── invoice + PDF ────────────────────────────────────────────────────────

    public function test_invoice_service_creates_a_consistent_paid_invoice(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $sub = $this->subscription($tenant);

        $invoice = app(InvoiceService::class)->createPaidForRenewal($sub);

        $this->assertSame('paid', $invoice->status);
        $this->assertSame(2900, $invoice->total_cents);
        $this->assertSame(2900, $invoice->subtotal_cents);
        $this->assertSame(0, $invoice->tax_cents);
        $this->assertStringStartsWith('INV-', $invoice->number);
        $this->assertNotNull($invoice->paid_at);
    }

    public function test_invoice_pdf_is_rendered_and_stored(): void
    {
        Storage::fake();
        [$tenant] = $this->tenantWithOwner();
        $sub = $this->subscription($tenant);
        $invoice = Invoice::factory()->forSubscription($sub)->create([
            'subtotal_cents' => 2900, 'tax_cents' => 0, 'total_cents' => 2900, 'status' => 'paid',
        ]);

        $path = app(InvoicePdfService::class)->generate($invoice);

        Storage::assertExists($path);
        $this->assertSame($path, $invoice->fresh()->pdf_url);
    }

    // ── event -> notification ────────────────────────────────────────────────

    public function test_renewal_issues_invoice_and_notifies_owner(): void
    {
        Storage::fake();
        Notification::fake();
        [$tenant, $owner] = $this->tenantWithOwner();
        $sub = $this->subscription($tenant);

        SubscriptionRenewed::dispatch($sub->id, (string) $tenant->id);

        $this->assertDatabaseHas('invoices', [
            'subscription_id' => $sub->id,
            'status' => 'paid',
            'total_cents' => 2900,
        ]);
        Notification::assertSentTo($owner, InvoiceReadyNotification::class);
    }

    public function test_charge_failure_notifies_owner(): void
    {
        Notification::fake();
        [$tenant, $owner] = $this->tenantWithOwner();
        $sub = $this->subscription($tenant, 'past_due');

        SubscriptionChargeFailed::dispatch($sub->id, (string) $tenant->id);

        Notification::assertSentTo($owner, ChargeFailedNotification::class);
    }

    public function test_suspension_notifies_owner(): void
    {
        Notification::fake();
        [$tenant, $owner] = $this->tenantWithOwner();
        $sub = $this->subscription($tenant, 'suspended');

        SubscriptionSuspended::dispatch($sub->id, (string) $tenant->id);

        Notification::assertSentTo($owner, SubscriptionSuspendedNotification::class);
    }

    public function test_trial_ending_notifies_owner(): void
    {
        Notification::fake();
        [$tenant, $owner] = $this->tenantWithOwner();
        $this->subscription($tenant, 'trialing');

        TrialEndingSoon::dispatch(1, (string) $tenant->id, 3);

        Notification::assertSentTo($owner, TrialEndingNotification::class);
    }

    public function test_falls_back_to_tenant_email_when_no_owner_user(): void
    {
        Notification::fake();
        $tenant = Tenant::factory()->create(['email' => 'billing@tenant.test']); // no owner attached

        TrialEndingSoon::dispatch(1, (string) $tenant->id, 2);

        Notification::assertSentOnDemand(TrialEndingNotification::class);
    }
}
