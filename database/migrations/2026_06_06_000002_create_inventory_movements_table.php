<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', static function (Blueprint $table): void {
            $table->id();
            $table->string('tenant_id', 26);
            $table->string('branch_id', 26);
            $table->foreignId('product_variant_id');

            $table->enum('type', ['entry', 'exit', 'adjustment', 'transfer']);

            // Signed: positive = entry, negative = exit / adjustment-out
            $table->integer('quantity');

            // Polymorphic-style reference without Eloquent morphTo, because reference_id
            // can be either bigint (as string) or ULID depending on the origin.
            $table->string('reference_type', 50)->nullable();
            $table->string('reference_id', 36)->nullable();

            $table->text('notes')->nullable();

            // Nullable: system-generated movements (e.g. automatic stock corrections) may
            // have no user_id. NullOnDelete preserves the ledger row when a user is deleted.
            $table->foreignId('user_id')->nullable();

            // Ledger is append-only — no updated_at column is intentional.
            $table->timestamp('created_at')->useCurrent();

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

            $table->foreign('user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            // --- Indexes ---
            // Chronological listing per branch (most common admin view)
            $table->index(['tenant_id', 'branch_id', 'created_at']);

            // History for a specific variant across time
            $table->index(['product_variant_id', 'created_at']);

            // Reports filtered by movement type
            $table->index(['type', 'created_at']);

            // Lookup all movements that belong to a given Order / Reservation / Transfer
            $table->index(['reference_type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
