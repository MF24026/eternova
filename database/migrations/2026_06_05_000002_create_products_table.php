<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 26);
            $table->string('name');
            $table->string('slug', 100);
            $table->text('description')->nullable();
            $table->string('sku_root', 60)->nullable();
            $table->unsignedInteger('base_price_cents');
            $table->unsignedInteger('cost_price_cents')->nullable();
            $table->string('default_image_url')->nullable();
            $table->json('gallery')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            // 5 digits total, 4 decimal places: e.g. 0.1300 = 13%
            $table->decimal('tax_rate', 5, 4)->nullable();
            $table->softDeletes();
            $table->timestamps();

            // FK
            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            // Indexes per ERD §3
            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'is_active', 'is_featured']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
