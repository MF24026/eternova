<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modified in-place for issue #9 (S0-E5): aligned with ERD §1 canonical schema.
 * Changes from the previous stub:
 *   - PK changed from ULID to bigint (ERD §2.1: high-volume operational table)
 *   - tenant_id FK stays as string(26) ULID ref to tenants
 *   - status enum narrowed to trialing|active|past_due|canceled (ERD §1)
 *   - Added cancel_at_period_end bool
 *   - Removed wompi_subscription_id (belongs to Sprint 9 Wompi integration)
 *   - Renamed cancelled_at to canceled_at for consistency with status value
 *   - Indexes updated per ERD §3
 *
 * One-active-subscription-per-tenant enforcement: MySQL does not support partial unique
 * indexes natively. The rule "a tenant can have at most one active subscription" is
 * enforced at the service layer in SubscriptionService::create() with a DomainException.
 * A regular unique index on (tenant_id, status) would break when a tenant cancels and
 * resubscribes (two 'canceled' rows). Service-layer enforcement is the correct approach.
 *
 * Safe to modify in-place — no production data exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->id();
            // tenant_id is ULID (string 26) — tenants table uses ULID PK
            $table->string('tenant_id', 26)->index();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->restrictOnDelete();
            $table->enum('status', ['trialing', 'active', 'past_due', 'canceled'])->default('trialing');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            // ERD §3 — primary subscription lookup per tenant
            $table->index(['tenant_id', 'status']);
            // ERD §3 — renewal cron iterates by period end
            $table->index('current_period_end');
            // ERD §3 — trial reminder cron iterates by trial end
            $table->index('trial_ends_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
