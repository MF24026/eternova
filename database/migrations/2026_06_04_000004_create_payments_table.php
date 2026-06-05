<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Created for issue #9 (S0-E5): payments table per ERD §1.
 *
 * A payment records a single charge attempt against an invoice.
 * One invoice can have multiple payment attempts (dunning retries).
 * The gateway_reference is the provider's transaction identifier — used for
 * deduplication and webhook reconciliation from Wompi / future gateways.
 *
 * raw_response stores the full gateway payload for post-mortem debugging.
 * It must NEVER be exposed in API responses or logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3);
            $table->enum('method', ['card', 'transfer', 'other']);
            $table->enum('status', ['pending', 'succeeded', 'failed'])->default('pending');
            $table->string('gateway');
            $table->string('gateway_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            // ERD §3 — successful payments for an invoice
            $table->index(['invoice_id', 'status']);
            // ERD §3 — webhook reconciliation lookup by gateway reference
            $table->index(['gateway', 'gateway_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
