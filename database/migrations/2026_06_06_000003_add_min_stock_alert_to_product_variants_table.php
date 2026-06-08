<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-variant low-stock alert threshold.
 *
 * Trade-off: we add an index on min_stock_alert even though bulk threshold queries
 * are not yet needed in Sprint 1. The index is cheap to add now (only non-NULL rows
 * are indexed in MySQL) and avoids a table-level ALTER later when a nightly
 * "find all variants below threshold" job is added in a future sprint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->unsignedInteger('min_stock_alert')
                ->nullable()
                ->after('weight_grams')
                ->comment('NULL = use per-tenant default or global config(inventory.default_min_stock)');

            $table->index('min_stock_alert');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropIndex(['min_stock_alert']);
            $table->dropColumn('min_stock_alert');
        });
    }
};
