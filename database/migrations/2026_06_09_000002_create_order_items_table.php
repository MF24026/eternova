<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted order_items table.
 *
 * Key design decisions vs the legacy schema:
 *  - product_variant_id instead of product_id: Shopify-style model — you always
 *    sell a specific variant (size/color combo), not a bare product.
 *  - unit_price_cents + total_cents in centavos (int), not decimal.
 *  - product_snapshot (json): captures name, variant options, and SKU at sale time.
 *    This is the "receipt immutability" guarantee — even if the product is later
 *    renamed, edited, or soft-deleted, the order item retains what was sold.
 *  - restrictOnDelete on product_variant_id: prevents accidental variant deletion
 *    when order history exists. Variants must be soft-deleted, not hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();

            // order_id is a ULID (string 26) — must match orders.id type exactly
            $table->string('order_id', 26);
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();

            // Shopify model: always link to the specific variant, not the abstract product
            $table->unsignedBigInteger('product_variant_id');
            $table->foreign('product_variant_id')
                ->references('id')
                ->on('product_variants')
                ->restrictOnDelete();

            $table->unsignedInteger('quantity');

            // Prices stored as centavos
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('total_cents');

            // Immutable snapshot of what was sold: name, variant options key-values, SKU.
            // Ensures receipt accuracy even when products are renamed or deleted afterwards.
            $table->json('product_snapshot');

            $table->timestamps();

            $table->index('order_id');
            $table->index('product_variant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
