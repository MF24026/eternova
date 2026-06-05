<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 60);
            $table->string('barcode', 60)->nullable();
            // nullable: falls back to product.base_price_cents when null
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedInteger('cost_price_cents')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            // e.g. {"Color": "Rojo", "Tamano": "Grande"}
            $table->json('options');
            $table->string('image_url')->nullable();
            $table->integer('position')->default(0);
            $table->softDeletes();
            $table->timestamps();

            // Indexes per ERD §3
            $table->unique(['product_id', 'sku']);
            $table->index(['product_id', 'position']);
            $table->index('barcode');
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
