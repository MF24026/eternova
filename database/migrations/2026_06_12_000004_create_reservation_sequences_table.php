<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-tenant reservation sequence table.
 *
 * Mirrors order_sequences exactly (see 2026_06_09_000003).
 *
 * Concurrency approach: instead of SELECT MAX(reservation_number) (races under
 * concurrent inserts), we maintain a dedicated sequence row per (tenant_id, year).
 * Inside every reservation-creation transaction, we:
 *   1. SELECT ... FOR UPDATE on this row (serialises concurrent requests per tenant)
 *   2. Increment the counter
 *   3. Use the new value to build reservation_number ("RSV-{year}-{padded_seq}")
 *
 * The FOR UPDATE lock is held until the enclosing DB::transaction() commits or
 * rolls back, preventing gaps and collisions under concurrent POS terminals.
 *
 * The sequence-claiming service method (ReservationService::nextReservationNumber)
 * is implemented in S5-E2. This migration creates only the table + model (E1 scope).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_sequences', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');

            // Monotonically incrementing counter; starts at 0 (first reservation increments to 1)
            $table->unsignedInteger('last_sequence')->default(0);

            $table->timestamps();

            // One row per tenant per year
            $table->unique(['tenant_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_sequences');
    }
};
