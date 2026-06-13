<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the prototype-era quotations and quotation_items tables so the
 * retrofitted schema can be created cleanly.
 *
 * The legacy tables (2026_04_17_000010) are broken:
 *   - No tenant_id — models use BelongsToTenant but the column never existed.
 *   - Money in subtotal/tax/total, not _cents columns.
 *   - No branch_id, no converted_order_id, no tax_rate_bps, no softDeletes.
 *   - quotation_items missing tenant_id, sort_order, and _cents suffixes.
 *
 * This mirrors the drop-legacy approach used for orders (2026_06_09_000000)
 * and reservations (2026_06_12_000001).
 *
 * Safe to run multiple times: both drops are guarded with hasTable().
 * Greenfield — no production data exists on these tables.
 *
 * FK drop order: quotation_items first (child), then quotations (parent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('quotation_items')) {
            Schema::drop('quotation_items');
        }

        if (Schema::hasTable('quotations')) {
            Schema::drop('quotations');
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally left empty: the create migrations that follow this one
        // are responsible for recreating the tables on rollback.
    }
};
