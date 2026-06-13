<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'quotation' to the orders.source ENUM column.
 *
 * MySQL ENUM changes require an ALTER TABLE … MODIFY COLUMN with the full
 * desired set. There is no additive ADD VALUE syntax that is safe across MySQL
 * versions — the explicit MODIFY guarantees the final state regardless of what
 * was there before.
 *
 * Default: 'pos' — matches the original CREATE TABLE default.
 *
 * down() caveat: removing 'quotation' from the ENUM when rows that use that
 * value exist would corrupt those rows (MySQL truncates unknown values to '').
 * The down() guards against this by refusing to run if any such rows exist.
 * If you need to force a rollback you must first update or delete those rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE orders MODIFY COLUMN source ENUM('pos','catalog','reservation','quotation') NOT NULL DEFAULT 'pos'"
        );
    }

    public function down(): void
    {
        // Guard: refuse to narrow the ENUM back if rows with source='quotation' exist.
        // Narrowing an ENUM column when live rows hold the removed value would silently
        // corrupt those rows to empty string in MySQL strict mode — or error in strict SQL.
        $quotationRowCount = DB::table('orders')->where('source', 'quotation')->count();

        if ($quotationRowCount > 0) {
            throw new \RuntimeException(
                "Cannot roll back add_quotation_to_orders_source_enum: {$quotationRowCount} order(s) "
                . "have source='quotation'. Delete or migrate those rows first, then re-run the rollback."
            );
        }

        DB::statement(
            "ALTER TABLE orders MODIFY COLUMN source ENUM('pos','catalog','reservation') NOT NULL DEFAULT 'pos'"
        );
    }
};
