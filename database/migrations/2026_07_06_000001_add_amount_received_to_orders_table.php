<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the cash tendered at a POS sale so the receipt can show "Recibí" and
 * the change due. Nullable: only cash sales capture it; card/transfer leave it
 * null. Change is derived (amount_received - total), not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedInteger('amount_received_cents')->nullable()->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('amount_received_cents');
        });
    }
};
