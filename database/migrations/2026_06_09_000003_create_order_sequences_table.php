<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant order sequence table.
 *
 * Concurrency approach: instead of SELECT MAX(order_number) (races under concurrent
 * inserts), we maintain a dedicated sequence row per (tenant_id, year). Inside every
 * order-creation transaction, we:
 *   1. SELECT ... FOR UPDATE on this row (serialises concurrent requests for the same tenant)
 *   2. Increment the counter
 *   3. Use the new value to build the order_number ("CC-{year}-{padded_seq}")
 *
 * Why this is safe: the FOR UPDATE lock is held until the enclosing DB::transaction()
 * commits or rolls back. If two POS terminals try to create an order simultaneously,
 * the second one blocks on the lock and proceeds only after the first commits, picking
 * up the incremented counter. No gap, no collision.
 *
 * Trade-off: one extra row lock per tenant per year. Under the expected POS load
 * (< 100 transactions/second per tenant) this is negligible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_sequences', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');

            // Monotonically incrementing counter; starts at 0 (first order increments to 1)
            $table->unsignedInteger('last_sequence')->default(0);

            $table->timestamps();

            // One row per tenant per year
            $table->unique(['tenant_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_sequences');
    }
};
