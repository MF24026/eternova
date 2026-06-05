<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modified in-place for issue #9 (S0-E5): aligned with ERD §1 canonical schema.
 * Changes from previous stub:
 *   - PK changed from ULID to bigint (ERD §2.1: high-volume operational table)
 *   - tenant_id and subscription_id now use the correct column types (ULID string vs bigint)
 *   - Column 'number' replaces 'invoice_number' — format INV-{year}-{seq}
 *   - status enum expanded to draft|open|paid|void|uncollectible
 *   - Replaced amount_cents with subtotal_cents + tax_cents + total_cents
 *   - Added currency, due_at, wompi_transaction_id, pdf_url
 *   - Indexes updated per ERD §3
 *
 * Safe to modify in-place — no production data exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->id();
            // tenant_id is ULID (string 26) — tenants table uses ULID PK
            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('number')->unique();
            $table->enum('status', ['draft', 'open', 'paid', 'void', 'uncollectible'])->default('draft');
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('tax_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->string('currency', 3);
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('wompi_transaction_id')->nullable();
            $table->string('pdf_url')->nullable();
            $table->timestamps();

            // ERD §3 — pending invoice lookups for a tenant
            $table->index(['tenant_id', 'status', 'due_at']);
            // ERD §3 — "invoices for this subscription" query
            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
