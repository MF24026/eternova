<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Phase 1 (Foundation): evolve `subscriptions` from the 4-state ERD stub
 * into the 9-state lifecycle machine, and add the operational + gateway columns the
 * later phases (recurring charges, dunning, reconciliation, payment method) rely on.
 *
 * Why widen `status` from enum to string: the State pattern needs 9 states
 * (trialing|active|past_due|paused|canceled|expired|suspended|soft_deleted|hard_deleted).
 * A native MySQL enum would have to be ALTERed every time the lifecycle grows; a plain
 * string column plus the SubscriptionStatus PHP enum keeps the source of truth in code.
 * Existing spellings (`trialing`, `canceled`) are preserved so the current rows, factory,
 * service, observer and SubscriptionTest keep working unchanged.
 *
 * The card_* columns store ONLY the gateway token + display metadata (last4, brand,
 * expiry). The PAN/CVV never touch our DB — gateway tokenization only (PCI SAQ-A).
 *
 * Safe to modify in-place — no production data exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Widen the status column to a free-form string so the 9-state machine fits.
        // `->change()` is native in Laravel 11+/12 — no doctrine/dbal required.
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('status', 20)->default('trialing')->change();
        });

        Schema::table('subscriptions', function (Blueprint $table): void {
            // Recurring-charge cron iterates by this; null until the sub becomes paid.
            $table->timestamp('next_billing_at')->nullable()->after('current_period_end');
            $table->timestamp('last_paid_at')->nullable()->after('next_billing_at');

            // Money snapshot at subscription time (plan price may drift later).
            $table->string('billing_period', 10)->default('monthly')->after('last_paid_at');
            $table->string('currency', 3)->default('USD')->after('billing_period');
            $table->unsignedInteger('amount_cents')->nullable()->after('currency');

            // Gateway linkage — populated once the tenant attaches a payment method.
            $table->string('gateway_subscription_id')->nullable()->after('amount_cents');
            $table->string('gateway_customer_id')->nullable()->after('gateway_subscription_id');

            // Payment method display metadata. card_token is the gateway token (encrypted
            // cast on the model) — NEVER the PAN. last4/brand/exp are for UI only.
            $table->text('card_token')->nullable()->after('gateway_customer_id');
            $table->string('card_last4', 4)->nullable()->after('card_token');
            $table->string('card_brand', 20)->nullable()->after('card_last4');
            $table->unsignedTinyInteger('card_exp_month')->nullable()->after('card_brand');
            $table->unsignedSmallInteger('card_exp_year')->nullable()->after('card_exp_month');

            // Dunning bookkeeping.
            $table->unsignedTinyInteger('retry_count')->default(0)->after('card_exp_year');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
            $table->timestamp('past_due_since')->nullable()->after('next_retry_at');

            $table->softDeletes();

            // Recurring-charge cron: "active subs whose next charge is due".
            $table->index(['status', 'next_billing_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'next_billing_at']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'next_billing_at',
                'last_paid_at',
                'billing_period',
                'currency',
                'amount_cents',
                'gateway_subscription_id',
                'gateway_customer_id',
                'card_token',
                'card_last4',
                'card_brand',
                'card_exp_month',
                'card_exp_year',
                'retry_count',
                'next_retry_at',
                'past_due_since',
            ]);
        });

        // Collapse any new-state rows back into the legacy enum domain before narrowing.
        DB::table('subscriptions')
            ->whereNotIn('status', ['trialing', 'active', 'past_due', 'canceled'])
            ->update(['status' => 'canceled']);

        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->enum('status', ['trialing', 'active', 'past_due', 'canceled'])
                ->default('trialing')
                ->change();
        });
    }
};
