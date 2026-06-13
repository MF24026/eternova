<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for every quotation status transition.
 *
 * Design mirrors reservation_status_history exactly (see 2026_06_12_000006):
 *  - No updated_at: rows are immutable once written. Only created_at is stamped.
 *  - from_status is nullable: null marks the initial creation entry where there
 *    was no prior status (the quotation was born directly into 'draft').
 *  - to_status is a plain string (not enum) to avoid ALTER TABLE when the status
 *    set grows. Valid values are enforced at the Service layer.
 *  - user_id is nullable: null means the transition was made by the system
 *    (e.g. ExpireQuotationsJob that runs daily and marks expired quotations).
 *  - tenant_id is present for the BelongsToTenant global scope — every history
 *    row is scoped to the tenant that owns the quotation.
 *
 * quotation_id is unsignedBigInteger because quotations.id is a bigint
 * autoincrement (created via $table->id()). Contrast with orders.id which is a
 * ULID string — always match the FK type to the referenced primary key exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_status_history', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedBigInteger('quotation_id');
            $table->foreign('quotation_id')->references('id')->on('quotations')->cascadeOnDelete();

            // null = this row represents the initial creation (no prior status)
            $table->string('from_status')->nullable();

            $table->string('to_status');

            // null = system-generated transition (e.g. ExpireQuotationsJob)
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->text('note')->nullable();

            // Append-only — only created_at, never updated_at.
            $table->timestamp('created_at')->nullable();

            // Timeline queries: load history for one quotation sorted by time
            $table->index(['quotation_id', 'created_at']);

            // Tenant-wide timeline queries (dashboard, reporting)
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_status_history');
    }
};
