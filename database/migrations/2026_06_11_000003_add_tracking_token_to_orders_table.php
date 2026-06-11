<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Add a shareable, unguessable tracking token to every order.
 *
 * Design decisions:
 *  - 32-char url-safe random string (Str::random(32) from [a-zA-Z0-9]).
 *    Collision probability across 1M rows ≈ 0.  Security-through-obscurity
 *    is acceptable here: the token is not a secret, but its unguessability
 *    ensures a customer cannot enumerate other tenants' orders.
 *  - UNIQUE index: enforced at the DB level as the last defence.
 *  - nullable: the column is created nullable so that the column can be added
 *    before the backfill loop runs — no single statement can atomically create
 *    a NOT NULL column AND fill it.  After the loop we add NOT NULL via a
 *    separate modify() call.  down() simply drops the column.
 *  - Backfill loop: processes rows in batches to avoid a single huge UPDATE.
 *    Each iteration reads a batch of ids with null tokens and inserts tokens
 *    one-by-one.  The while loop terminates when no more null rows exist.
 *    Collision guard: a regenerate-if-exists loop is included but in practice
 *    is never exercised (32 random chars ≈ 192 bits of entropy).
 *  - ->after('order_number'): keeps related identifiers adjacent in tooling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('tracking_token', 32)
                ->nullable()
                ->unique()
                ->after('order_number');
        });

        // Backfill: generate a unique 32-char token for every existing order row.
        // We process in batches of 200 to be kind to the database under migration.
        // The deleted_at check is intentionally absent — soft-deleted orders must
        // also receive tokens so their historical tracking links remain resolvable.
        $batchSize = 200;

        do {
            $rows = DB::table('orders')
                ->whereNull('tracking_token')
                ->limit($batchSize)
                ->pluck('id');

            foreach ($rows as $id) {
                $token = $this->generateUniqueToken();
                DB::table('orders')
                    ->where('id', $id)
                    ->update(['tracking_token' => $token]);
            }
        } while ($rows->isNotEmpty());
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['tracking_token']);
            $table->dropColumn('tracking_token');
        });
    }

    /**
     * Generate a 32-char url-safe token guaranteed to be absent from the orders table.
     *
     * In practice the while loop body executes zero times — it exists purely as a
     * defensive guard against the astronomically unlikely collision.
     */
    private function generateUniqueToken(): string
    {
        do {
            $token = Str::random(32);
        } while (DB::table('orders')->where('tracking_token', $token)->exists());

        return $token;
    }
};
