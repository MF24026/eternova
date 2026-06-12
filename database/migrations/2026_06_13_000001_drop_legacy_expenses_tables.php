<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the prototype-era expenses and expense_categories tables so the
 * retrofitted schema can be created cleanly.
 *
 * The legacy tables (2026_04_17_000009) are broken:
 *   - expenses: no tenant_id — model uses BelongsToTenant but the column never existed.
 *   - expenses: amount (not amount_cents), date (not expense_date), receipt_image
 *     (not receipt_path), user_id (not created_by), no branch_id, no payment_method,
 *     no ocr_status, no softDeletes.
 *   - expense_categories: no tenant_id — global table, not per-tenant.
 *   - expense_categories: no is_active column.
 *
 * This mirrors the drop-legacy approach used for reservations in 2026_06_12_000001.
 *
 * Safe to run multiple times: both drops are guarded with hasTable().
 * Greenfield — no production data exists on these tables.
 *
 * FK drop order: expenses first (child), then expense_categories (parent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('expenses')) {
            Schema::drop('expenses');
        }

        if (Schema::hasTable('expense_categories')) {
            Schema::drop('expense_categories');
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally left empty: the create migrations that follow this one
        // are responsible for recreating the tables on rollback.
    }
};
