<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Phase 4 (crons):
 *   - trial_reminder_sent_at on subscriptions makes the trial-reminder cron idempotent
 *     (a daily run never re-spams a tenant we already warned).
 *   - reconciliation_discrepancies is the manual-review queue the reconcile cron writes to
 *     when our subscription state drifts from what the gateway reports.
 *
 * tenant_id is ULID string(26) to match the tenants PK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('trial_reminder_sent_at')->nullable()->after('past_due_since');
        });

        Schema::create('reconciliation_discrepancies', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->json('our_state');
            $table->json('gateway_state');
            $table->string('diff_summary');
            $table->boolean('resolved')->default(false);
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->index(['resolved', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_discrepancies');

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('trial_reminder_sent_at');
        });
    }
};
