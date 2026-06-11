<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit log for every order status transition.
 *
 * Design decisions:
 *  - No updated_at: rows are immutable once written. Only created_at is stamped.
 *  - from_status is nullable: null marks the initial creation entry where there
 *    was no prior status (the order came into existence in that status).
 *  - to_status is a plain string (not enum) to avoid costly ALTER TABLE when
 *    the status set grows. The set of valid values is enforced at the Service layer.
 *  - user_id is nullable: null means the transition was made by the system or
 *    by the customer (e.g. a webhook or a scheduled job).
 *  - tenant_id is present for the BelongsToTenant global scope — every history
 *    row is scoped to the tenant that owns the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->string('order_id', 26);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            // null = this row represents the initial creation (no prior status)
            $table->string('from_status')->nullable();

            $table->string('to_status');

            // null = system-generated or customer-initiated transition
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->text('note')->nullable();

            // Append-only — only created_at, never updated_at.
            $table->timestamp('created_at')->nullable();

            // Timeline queries: load history for one order sorted by time
            $table->index(['order_id', 'created_at']);

            // Tenant-wide timeline queries (dashboard, reporting)
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
