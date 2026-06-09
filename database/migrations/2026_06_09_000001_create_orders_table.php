<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted orders table.
 *
 * Key design decisions vs the legacy schema:
 *  - ULID primary key (readable in receipts, sortable by creation time)
 *  - branch_id added: every sale belongs to the branch where it was rung up
 *  - All monetary columns suffixed with _cents and stored as int (not decimal/float)
 *  - discount_cents added for future coupon/promo support
 *  - payment_method extended with 'other' case
 *  - user_id records which staff member closed the sale
 *  - softDeletes for audit trail (cancelled orders are never physically removed)
 *
 * order_number uniqueness: enforced by UNIQUE(tenant_id, order_number).
 * The application generates numbers as CC-{year}-{seq} using a per-tenant
 * locked sequence row (see OrderService::nextOrderNumber).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table): void {
            // ULID primary key — human-readable in receipts, lexicographically sortable
            $table->ulid('id')->primary();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // branch_id: which physical location rang up this sale
            $table->string('branch_id', 26);
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();

            // nullable: walk-in customer (no registered profile) is valid for POS
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            $table->string('order_number', 40);

            $table->enum('status', [
                'pending',
                'preparing',
                'ready',
                'dispatched',
                'delivered',
                'cancelled',
            ])->default('pending');

            $table->enum('source', ['pos', 'catalog', 'reservation'])->default('pos');

            // All monetary values in centavos (integer). Never decimal — avoids float rounding.
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);
            $table->unsignedInteger('total_cents');

            // nullable: payment can be deferred (e.g. catalog order not yet paid)
            $table->enum('payment_method', ['cash', 'card', 'transfer', 'other'])->nullable();
            $table->enum('payment_status', ['pending', 'partial', 'paid'])->default('pending');

            $table->text('notes')->nullable();

            // which staff member closed the sale
            $table->unsignedBigInteger('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // One order number per tenant (e.g. CC-2026-0001 can exist in tenant A and tenant B)
            $table->unique(['tenant_id', 'order_number']);

            // POS dashboard: filter by branch + status
            $table->index(['tenant_id', 'branch_id', 'status']);

            // Reporting: date-range queries on orders
            $table->index(['tenant_id', 'created_at']);

            // Customer order history lookup
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
