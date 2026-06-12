<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted expenses table.
 *
 * Key design decisions vs the legacy schema (2026_04_17_000009):
 *   - tenant_id added: THE critical fix — legacy had BelongsToTenant but no column.
 *   - branch_id added: multi-branch support (nullable — a head-office expense may
 *     not belong to a specific branch).
 *   - amount_cents replaces amount: all monetary values stored as integer centavos,
 *     never decimal. Avoids float rounding on totals/aggregates.
 *   - expense_date replaces date: avoids collision with MySQL reserved word; clearer
 *     domain language consistent with event_date on reservations.
 *   - receipt_path replaces receipt_image: language-neutral — supports both image
 *     and PDF uploads. Absolute paths are never stored; values are storage-disk keys.
 *   - ocr_status enum added: drives the OCR pipeline state machine
 *     (none → pending → processing → done | failed). Queried by the poller.
 *   - payment_method added: cash-heavy LatAm context where knowing HOW a vendor
 *     was paid matters for reconciliation.
 *   - created_by replaces user_id: naming is consistent with orders, reservations,
 *     and reservation_payments (all use created_by for the initiating staff member).
 *   - softDeletes: deleted expenses are kept for audit trail (never physically removed).
 *
 * OCR pipeline states:
 *   none       — manual entry, no receipt uploaded.
 *   pending    — receipt uploaded, job dispatched but not yet started.
 *   processing — job is currently running Tesseract.
 *   done       — OCR completed; ocr_data contains the extracted suggestions.
 *   failed     — OCR exhausted retries; expense remains a draft (is_verified=false).
 *
 * The is_verified flag is the human gate: false = draft (OCR suggestions not yet
 * confirmed by staff), true = staff-confirmed final record. This invariant is
 * enforced at the service layer, never by the DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            // nullable: a head-office or company-wide expense may not belong to a branch
            $table->string('branch_id', 26)->nullable();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            // nullable: expenses can exist before being categorised (e.g. draft from OCR)
            $table->unsignedBigInteger('expense_category_id')->nullable();
            $table->foreign('expense_category_id')->references('id')->on('expense_categories')->nullOnDelete();

            $table->string('description');

            // All monetary values in centavos (integer). Never decimal — avoids float rounding.
            $table->unsignedInteger('amount_cents')->default(0);

            $table->date('expense_date');

            $table->string('vendor')->nullable();

            $table->enum('payment_method', ['cash', 'card', 'transfer', 'other'])->nullable();

            // Storage disk key for the uploaded receipt (image or PDF). Never an absolute path.
            $table->string('receipt_path')->nullable();

            // OCR pipeline state: none → pending → processing → done | failed
            $table->enum('ocr_status', ['none', 'pending', 'processing', 'done', 'failed'])->default('none');

            // Structured extraction from Tesseract: { vendor, amount_cents, date, raw_text, confidence }
            $table->json('ocr_data')->nullable();

            // Human gate: false = draft (needs staff confirmation); true = final confirmed record.
            $table->boolean('is_verified')->default(false);

            $table->text('notes')->nullable();

            // Staff member who entered or uploaded this expense.
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // Most common query: tenant's expense ledger sorted by date.
            $table->index(['tenant_id', 'expense_date']);

            // Category breakdown: tenant's expenses by category (monthly report).
            $table->index(['tenant_id', 'expense_category_id']);

            // OCR queue polling: find pending/processing jobs for a tenant.
            $table->index(['tenant_id', 'ocr_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
