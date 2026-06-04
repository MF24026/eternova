<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('tenant_id', 26);
            $table->string('name');
            $table->string('slug', 63);
            $table->text('address')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')
                ->on('tenants')
                ->cascadeOnDelete();

            // Slug must be unique within a tenant
            $table->unique(['tenant_id', 'slug']);

            // Primary index for fetching active branches of a tenant
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
