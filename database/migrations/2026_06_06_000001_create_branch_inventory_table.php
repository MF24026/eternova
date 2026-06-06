<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_inventory', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 26);
            $table->string('branch_id', 26);
            $table->foreignId('product_variant_id');
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('reserved')->default(0);

            // GENERATED column: available = quantity - reserved.
            // CAST is required because MySQL cannot subtract two UNSIGNED INT columns
            // without potentially wrapping to a huge unsigned value when the result is negative.
            // Using SIGNED casts produces the expected signed integer result.
            $table->integer('available')->storedAs(
                'CAST(quantity AS SIGNED) - CAST(reserved AS SIGNED)'
            );

            $table->timestamps();

            // --- Foreign keys ---
            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();

            $table->foreign('branch_id')
                ->references('id')->on('branches')
                ->cascadeOnDelete();

            $table->foreign('product_variant_id')
                ->references('id')->on('product_variants')
                ->cascadeOnDelete();

            // --- Indexes ---
            // One inventory row per (tenant, branch, variant) — the core constraint
            $table->unique(['tenant_id', 'branch_id', 'product_variant_id']);

            // Listing all variants in a branch (most common read)
            $table->index(['tenant_id', 'branch_id']);

            // Cross-branch stock total for a single variant
            $table->index(['product_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_inventory');
    }
};
