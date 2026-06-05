<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2M pivot between categories and products.
 *
 * Why tenant_id here: every business table carries tenant_id (ERD §2.7). This
 * allows tenant-scoped scans on the pivot without an extra JOIN to products or
 * categories, and the BelongsToTenant trait auto-sets it on attach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 26);
            $table->unsignedBigInteger('category_id');
            $table->unsignedBigInteger('product_id');
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // FKs
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->cascadeOnDelete();

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            // Natural PK per ERD §3 (composite unique instead of a separate PK constraint)
            $table->unique(['category_id', 'product_id']);

            // Indexes per ERD §3
            $table->index('tenant_id');
            $table->index(['category_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_product');
    }
};
