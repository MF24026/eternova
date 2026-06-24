<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wompi owns the recurring charge schedule and its retries (EnlacePagoRecurrente),
 * so the app never drives dunning retries itself: `retry_count` was only ever set
 * to 0 and `next_retry_at` only ever to null. Drop both — they were vestigial.
 *
 * `past_due_since` (the dunning entry timestamp we DO use) stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn(['retry_count', 'next_retry_at']);
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('retry_count')->default(0)->after('card_exp_year');
            $table->timestamp('next_retry_at')->nullable()->after('retry_count');
        });
    }
};
