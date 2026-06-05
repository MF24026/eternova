<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M2M pivot between products and tags.
 *
 * No tenant_id column per ERD §2.8 — this pivot is always queried via Product
 * which already carries the tenant scope. Adding tenant_id here would be noise
 * with no query benefit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tag', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('tag_id');
            $table->timestamps();

            // Composite PK per ERD §3
            $table->primary(['product_id', 'tag_id']);

            $table->foreign('product_id')
                ->references('id')
                ->on('products')
                ->cascadeOnDelete();

            $table->foreign('tag_id')
                ->references('id')
                ->on('tags')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tag');
    }
};
