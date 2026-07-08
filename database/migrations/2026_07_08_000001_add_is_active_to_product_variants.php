<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-variant on/off switch (Shopify-style). Inactive variants stay configured
 * but are hidden from the public storefront and the POS — a way to discontinue a
 * variant without deleting it. Distinct from stock, which is quantity-based and
 * transient. Defaults to active so existing variants are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('is_active');
        });
    }
};
