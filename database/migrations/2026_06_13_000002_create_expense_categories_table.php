<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the retrofitted expense_categories table.
 *
 * Key design decisions vs the legacy schema (2026_04_17_000009):
 *   - tenant_id added: categories are per-tenant, not global. A florist can have
 *     "Renta" and a gift shop might rename it "Almacen" — the list is theirs to own.
 *   - UNIQUE(tenant_id, name): two different tenants may use the same category name
 *     independently; two records with the same name within one tenant are blocked.
 *   - is_active added: tenants can retire categories without hard-deleting them,
 *     preserving the historical link from existing expense rows.
 *   - type enum is unchanged (operating, products, payroll, rent, other) — these
 *     represent the financial reporting buckets that the monthly report will group by.
 *
 * Seeded with 5 default categories per demo tenant by
 * Database\Seeders\Expenses\ExpenseCategoriesSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table): void {
            $table->id();

            $table->string('tenant_id', 26);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();

            $table->string('name');

            // Financial reporting bucket — used for the monthly report grouping.
            $table->enum('type', ['operating', 'products', 'payroll', 'rent', 'other'])->default('other');

            // Soft-disable: retired categories stay linked to historical expenses.
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One category name per tenant — different tenants may share the same name.
            $table->unique(['tenant_id', 'name']);

            // All category lookups are tenant-scoped.
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_categories');
    }
};
