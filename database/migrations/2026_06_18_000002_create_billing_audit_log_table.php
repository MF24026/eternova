<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Phase 1 (Foundation): append-only audit trail for every billing-relevant
 * domain event (state changes, charges, refunds, operator actions).
 *
 * Append-only by doctrine: the BillingAuditLog model throws on update. The only way to
 * "correct" an entry is to append a compensating one. Retained for tax/AML compliance
 * (5 years in CO/SV) — never hard-deleted with the tenant, hence nullOnDelete below so a
 * tenant teardown leaves the anonymized trail intact.
 *
 * tenant_id is ULID string(26) to match the tenants PK (NOT foreignId — this project's
 * tenants table uses a ULID primary key).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_audit_log', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 26)->nullable();
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()
                ->constrained('subscriptions')->nullOnDelete();
            $table->string('event_type');
            $table->json('payload');
            $table->string('correlation_id', 36)->index();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'event_type']);
            $table->index(['subscription_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_audit_log');
    }
};
