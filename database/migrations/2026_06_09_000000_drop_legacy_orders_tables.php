<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the prototype-era orders/order_items tables so the fresh schema can be
 * created cleanly. This mirrors the drop-legacy approach used in #31 for the
 * catalog module.
 *
 * Safe to run multiple times: both drops are guarded with hasTable().
 * Greenfield — no production data exists on these tables.
 *
 * FK drop order: order_items first (child), then orders (parent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('order_items')) {
            Schema::drop('order_items');
        }

        if (Schema::hasTable('orders')) {
            Schema::drop('orders');
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Intentionally left empty: the create migrations that follow this one
        // are responsible for recreating the tables on rollback.
    }
};
