<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted reservation_payments table.
 *
 * Key design decisions vs the legacy schema (2026_04_17_000008):
 *   - tenant_id added (THE critical fix — shared with reservations).
 *   - amount_cents replaces amount (consistent _cents convention).
 *   - payment_method enum adds 'other' to cover non-standard methods.
 *   - recorded_by: FK to users for full staff accountability.
 *
 * Each row represents a single payment instalment against a reservation.
 * The service layer is responsible for incrementing deposit_paid_cents on
 * the parent reservation after each successful payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_payments', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedBigInteger('reservation_id');
            $table->foreign('reservation_id')->references('id')->on('reservations')->cascadeOnDelete();

            // Amount paid in this instalment, in centavos
            $table->unsignedInteger('amount_cents');

            $table->enum('payment_method', ['cash', 'card', 'transfer', 'other']);

            $table->string('reference')->nullable();

            // Staff member who recorded this payment
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->foreign('recorded_by')->references('id')->on('users')->nullOnDelete();

            // When the physical payment occurred (may differ from created_at for backdating)
            $table->timestamp('paid_at');

            $table->timestamps();

            // Reporting: payments for a reservation ordered by payment date
            $table->index(['reservation_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_payments');
    }
};
