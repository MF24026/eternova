<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the prototype-era reservations/reservation_payments tables so the
 * retrofitted schema can be created cleanly.
 *
 * The legacy tables (2026_04_17_000008) are broken:
 *   - No tenant_id — models use BelongsToTenant but the column never existed.
 *   - Money in total_amount/deposit_amount/deposit_paid, not _cents columns.
 *   - No branch_id, no converted_order_id, no reservation_number.
 *   - reservation_payments missing tenant_id, recorded_by, and 'other' method.
 *
 * This mirrors the drop-legacy approach used for orders in 2026_06_09_000000.
 *
 * Safe to run multiple times: both drops are guarded with hasTable().
 * Greenfield — no production data exists on these tables.
 *
 * FK drop order: reservation_payments first (child), then reservations (parent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('reservation_payments')) {
            Schema::drop('reservation_payments');
        }

        if (Schema::hasTable('reservations')) {
            Schema::drop('reservations');
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally left empty: the create migrations that follow this one
        // are responsible for recreating the tables on rollback.
    }
};
