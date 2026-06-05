<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the Sprint-0 prototype catalog tables and their cross-table FKs.
 *
 * Why: The original single-tenant migrations (2026_04_17_*) created `categories`
 * and `products` with a flat, single-category schema. Sprint 1 replaces them
 * with a Shopify-style schema (M2M categories, variants, options).
 *
 * Before dropping the old tables, we must remove FKs in child tables that point to
 * `products`. Those child tables (orders, quotations, inventory_movements) are
 * Sprint 3-7 scope — they keep their rows but their product_id becomes a plain
 * nullable integer until those modules are reworked.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop FKs that reference the old `products` table.
        // Each conditional guard prevents failures on repeated migrate:fresh runs.
        if (Schema::hasTable('order_items') && Schema::hasColumn('order_items', 'product_id')) {
            Schema::table('order_items', function (Blueprint $table): void {
                $table->dropForeign(['product_id']);
            });
        }

        if (Schema::hasTable('quotation_items') && Schema::hasColumn('quotation_items', 'product_id')) {
            Schema::table('quotation_items', function (Blueprint $table): void {
                $table->dropForeign(['product_id']);
            });
        }

        if (Schema::hasTable('inventory_movements') && Schema::hasColumn('inventory_movements', 'product_id')) {
            Schema::table('inventory_movements', function (Blueprint $table): void {
                $table->dropForeign(['product_id']);
            });
        }

        // Drop category FK from the old single-category products table (if it exists).
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'category_id')) {
            Schema::table('products', function (Blueprint $table): void {
                $table->dropForeign(['category_id']);
            });
        }

        // Drop the old inventory_movements table — Sprint 1 E5 will recreate it with
        // the new schema (branch_id + product_variant_id instead of product_id).
        Schema::dropIfExists('inventory_movements');

        // Drop old catalog tables. Categories must come after products because of the
        // self-referencing parent_id FK.
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }

    public function down(): void
    {
        // This migration is not reversible in isolation — restoring the old tables
        // would require re-running the 2026_04_17_* migrations in order.
        // Run migrate:fresh to restore the full sequence from scratch.
    }
};
