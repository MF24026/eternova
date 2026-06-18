<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing Phase 2: deduplication ledger for money operations. Every charge/refund/tokenize
 * runs through IdempotencyService, which firstOrCreate()s a row here keyed by
 * (key, operation, tenant_id). The composite UNIQUE is the source of truth — a duplicate
 * request collides at the DB level even under a race, so the gateway is never hit twice for
 * the same logical operation.
 *
 * tenant_id is ULID string(26) to match the tenants PK (NOT foreignId).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotent_operations', function (Blueprint $table): void {
            $table->id();
            $table->string('key');
            $table->string('operation');           // 'charge' | 'refund' | 'tokenize'
            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->enum('status', ['pending', 'completed', 'failed']);
            $table->longText('result')->nullable();   // serialized result DTO on success
            $table->text('error_message')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['key', 'operation', 'tenant_id']);
            $table->index('expires_at');   // sweeper for expired pending rows
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotent_operations');
    }
};
