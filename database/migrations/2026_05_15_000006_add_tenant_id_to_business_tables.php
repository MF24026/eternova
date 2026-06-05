<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that belong to a tenant and need the tenant_id column.
     *
     * Order matters for the down() rollback: child tables first (no FK deps on each other here,
     * but keeping them logically grouped makes auditing easier).
     *
     * @var list<string>
     */
    private array $businessTables = [
        'settings',
        'categories',
        'products',
        'customers',
        'inventory_movements',
        'orders',
        'order_items',
        'reservations',
        'reservation_payments',
        'expense_categories',
        'expenses',
        'quotations',
        'quotation_items',
    ];

    /**
     * Strategy:
     *  1. Add tenant_id as NULLABLE with FK first (allows INSERT on existing rows).
     *  2. Change to NOT NULL.
     *
     * Backfill step removed in #28: the residual "demo" tenant creation that lived
     * here was a single-tenant-phase artefact. On greenfield the backfill was always
     * a no-op (empty tables); on migrate:fresh it produced a stray tenant row.
     */
    public function up(): void
    {
        foreach ($this->businessTables as $table) {
            // Step 1 — add nullable column + FK
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('tenant_id', 26)
                    ->nullable()
                    ->after('id');

                $blueprint->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();

                $blueprint->index('tenant_id');
            });

            // Step 2 — make NOT NULL
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->string('tenant_id', 26)->nullable(false)->change();
            });
        }
    }

    /**
     * Remove tenant_id FK and column from all business tables.
     */
    public function down(): void
    {
        foreach (array_reverse($this->businessTables) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropIndex(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
