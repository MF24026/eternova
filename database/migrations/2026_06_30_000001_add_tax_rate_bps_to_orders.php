<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // Basis points snapshot of the rate applied at sale time (nullable:
            // pre-existing orders and non-POS sources leave it null).
            $table->unsignedSmallInteger('tax_rate_bps')->nullable()->after('tax_cents');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('tax_rate_bps');
        });
    }
};
