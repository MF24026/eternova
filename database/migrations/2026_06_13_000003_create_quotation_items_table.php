<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted quotation_items table.
 *
 * Key design decisions vs the legacy schema (2026_04_17_000010):
 *   - tenant_id added (required by BelongsToTenant global scope).
 *   - unit_price_cents / line_total_cents: _cents suffix, unsignedInt (not 'unit_price'/'total').
 *   - sort_order: controls line ordering in the PDF; lines can be reordered by the UI.
 *   - description is a snapshot: stores the product name at quote-creation time so the
 *     historical PDF is not affected if the product name changes later.
 *   - product_id is nullable: supports free-text lines not tied to a catalog product.
 *
 * product_id FK type: products.id is a bigint autoincrement ($table->id() in
 * 2026_06_05_000002_create_products_table.php). FK must use unsignedBigInteger
 * to match exactly.
 *
 * quotation_id FK type: quotations.id is a bigint autoincrement ($table->id()),
 * so unsignedBigInteger is correct here too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->unsignedBigInteger('quotation_id');
            $table->foreign('quotation_id')->references('id')->on('quotations')->cascadeOnDelete();

            // nullable: a line item may be free-text (not linked to a catalog product)
            $table->unsignedBigInteger('product_id')->nullable();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();

            // Snapshot of the product/service name at quote time — frozen so historical
            // PDF remains accurate even if the product record changes later.
            $table->string('description');

            $table->unsignedInteger('quantity');

            // All monetary values in centavos (integer). Never decimal.
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('line_total_cents');

            // Controls the display order of lines in the PDF and UI builder.
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            // Efficient ordered load of a quotation's lines
            $table->index(['quotation_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
