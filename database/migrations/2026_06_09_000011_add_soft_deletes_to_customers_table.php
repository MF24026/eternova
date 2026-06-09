<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft-delete support to customers (S3-E2). The customer module deletes are
 * soft (archive + restore from the POS / CRM), so the deleted_at column is required.
 * Guarded so it is safe on environments that already have the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('customers', 'deleted_at')) {
            return;
        }

        Schema::table('customers', static function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'deleted_at')) {
            return;
        }

        Schema::table('customers', static function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
