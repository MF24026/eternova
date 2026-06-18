<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Phase 3: make webhook_log an idempotent receipt ledger.
 *
 * The receiver firstOrCreate()s a row keyed by (gateway, event_id) and the UNIQUE makes a
 * replayed event collide at the DB level, so it is processed exactly once. `status` tracks
 * the async lifecycle (received -> processing -> completed|failed); `received_at` records
 * arrival. The existing processed_at/error columns stay for backward compatibility with the
 * model's markProcessed()/markFailed() helpers.
 *
 * event_id is nullable so legacy/non-id payloads still log; MySQL allows multiple NULLs in a
 * UNIQUE, so only real, repeated event ids dedupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_log', function (Blueprint $table): void {
            $table->string('event_id')->nullable()->after('event_type');
            $table->enum('status', ['received', 'processing', 'completed', 'failed'])
                ->default('received')->after('event_id');
            $table->timestamp('received_at')->nullable()->after('status');

            $table->unique(['gateway', 'event_id']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_log', function (Blueprint $table): void {
            $table->dropUnique(['gateway', 'event_id']);
            $table->dropIndex(['status']);
            $table->dropColumn(['event_id', 'status', 'received_at']);
        });
    }
};
