<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted reservations table.
 *
 * Key design decisions vs the legacy schema (2026_04_17_000008):
 *   - tenant_id added (THE critical fix — legacy had BelongsToTenant but no column).
 *   - branch_id added: multi-branch support, nullable because a reservation may be
 *     captured before a branch is assigned.
 *   - All monetary columns suffixed with _cents, stored as unsignedInt (not decimal).
 *   - reservation_number added: RSV-{year}-{seq}, unique per tenant.
 *   - converted_order_id char(26): links to orders.id (ULID, string length 26).
 *     Type must match orders.id exactly — the FK references a ULID primary key.
 *   - assigned_to / created_by: staff accountability, consistent with Orders.
 *   - softDeletes: cancelled reservations are never physically removed (audit trail).
 *   - event_date replaces delivery_date (clearer domain language).
 *
 * reservation_number uniqueness: enforced by UNIQUE(tenant_id, reservation_number).
 * The application generates numbers as RSV-{year}-{seq} using a per-tenant locked
 * sequence row (see ReservationService::nextReservationNumber in a later epic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // nullable: a reservation may start without a branch assignment
            $table->string('branch_id', 26)->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // nullable: a reservation can exist without a registered customer profile
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            $table->string('reservation_number', 40);

            $table->text('description');

            $table->string('occasion')->nullable();

            // The date the finished piece should be delivered / the event occurs
            $table->date('event_date')->nullable();

            // All monetary values in centavos (integer). Never decimal — avoids float rounding.
            $table->unsignedInteger('total_cents')->default(0);
            $table->unsignedInteger('deposit_required_cents')->default(0);
            $table->unsignedInteger('deposit_paid_cents')->default(0);

            $table->enum('status', [
                'inquiry',
                'confirmed',
                'in_progress',
                'ready',
                'delivered',
                'cancelled',
            ])->default('inquiry');

            $table->text('special_instructions')->nullable();
            $table->text('admin_notes')->nullable();

            // char(26): orders.id is a ULID stored as char(26). The FK type MUST match.
            $table->char('converted_order_id', 26)->nullable();
            $table->foreign('converted_order_id')->references('id')->on('orders')->nullOnDelete();

            // Staff member responsible for fulfilling this reservation
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            // Staff member who captured the reservation
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // One reservation number per tenant (RSV-2026-0001 can exist in tenant A and B)
            $table->unique(['tenant_id', 'reservation_number']);

            // Dashboard queries: filter by tenant + status (board/kanban view)
            $table->index(['tenant_id', 'status']);

            // Calendar view: filter by tenant + event_date range
            $table->index(['tenant_id', 'event_date']);

            // Customer reservation history lookup
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
