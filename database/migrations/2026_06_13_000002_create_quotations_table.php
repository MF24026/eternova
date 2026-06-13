<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted quotations table.
 *
 * Key design decisions vs the legacy schema (2026_04_17_000010):
 *   - tenant_id added (THE critical fix — legacy had BelongsToTenant but no column).
 *   - branch_id added: multi-branch support, nullable because a quotation may be
 *     created before a branch is assigned.
 *   - All monetary columns suffixed with _cents, stored as unsignedInt (not decimal).
 *   - tax_rate_bps: tax rate stored in basis points (1300 = 13% IVA SV, 1900 = 19% CO).
 *     This avoids float arithmetic; 1 bps = 0.01%. Never store tax as decimal/float.
 *   - discount_cents added: global line-level discount before tax application.
 *   - quotation_number added: COT-{year}-{seq}, unique per tenant (mirrors reservation_number).
 *   - converted_order_id char(26): links to orders.id (ULID, string length 26).
 *     Type must match orders.id exactly — the FK references a ULID primary key.
 *   - assigned_to / created_by: staff accountability, consistent with Orders and Reservations.
 *   - terms field added: pre-filled from tenant default (quotation_terms), overridable per quote.
 *   - softDeletes: rejected/expired quotations are never physically removed (audit trail).
 *   - issue_date replaces date (clearer domain language, aligns with PDF terminology).
 *
 * quotation_number uniqueness: enforced by UNIQUE(tenant_id, quotation_number).
 * The application generates numbers as COT-{year}-{seq} using a per-tenant locked
 * sequence row (see QuotationService::nextQuotationNumber in a later epic).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // nullable: a quotation may start without a branch assignment
            $table->string('branch_id', 26)->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // nullable: a quotation can exist without a registered customer profile
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();

            $table->string('quotation_number', 40);

            $table->date('issue_date');
            $table->date('valid_until')->nullable();

            // All monetary values in centavos (integer). Never decimal — avoids float rounding.
            $table->unsignedInteger('subtotal_cents')->default(0);
            $table->unsignedInteger('discount_cents')->default(0);

            // Tax rate in basis points (100 bps = 1%). E.g. 1300 = 13% IVA El Salvador.
            // Stored as integer to avoid floating-point imprecision in rate calculations.
            $table->unsignedSmallInteger('tax_rate_bps')->default(0);
            $table->unsignedInteger('tax_cents')->default(0);

            // total_cents = subtotal_cents - discount_cents + tax_cents
            $table->unsignedInteger('total_cents')->default(0);

            $table->enum('status', [
                'draft',
                'sent',
                'accepted',
                'rejected',
                'expired',
            ])->default('draft');

            $table->text('notes')->nullable();
            $table->text('terms')->nullable();

            // char(26): orders.id is a ULID stored as char(26). The FK type MUST match.
            $table->char('converted_order_id', 26)->nullable();
            $table->foreign('converted_order_id')->references('id')->on('orders')->nullOnDelete();

            // Staff member responsible for following up on this quotation
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            // Staff member who originally created this quotation
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // One quotation number per tenant (COT-2026-0001 can exist in tenant A and B)
            $table->unique(['tenant_id', 'quotation_number']);

            // Dashboard queries: filter by tenant + status (board/kanban view)
            $table->index(['tenant_id', 'status']);

            // Date range queries: filter by tenant + issue_date
            $table->index(['tenant_id', 'issue_date']);

            // Customer quotation history lookup
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
