<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Created for issue #9 (S0-E5): webhook_log table per ERD §1.
 *
 * Intentionally has NO foreign key to tenants. Webhooks can arrive after a tenant
 * has been deleted, and the log must remain immutable for audit/debugging purposes.
 * See ERD §2.4 for the full rationale.
 *
 * processed_at null = not yet processed (or processing failed).
 * error non-null = last processing attempt failed with this message.
 * The log is append-only — never update rows for correction; instead append a new
 * row or update only processed_at + error for retry state tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_log', function (Blueprint $table): void {
            $table->id();
            $table->string('gateway');
            $table->string('event_type');
            $table->json('payload');
            $table->string('signature');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            // ERD §3 — search by gateway and event type for debugging
            $table->index(['gateway', 'event_type', 'created_at']);
            // ERD §3 — job that retries unprocessed webhooks
            $table->index('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_log');
    }
};
