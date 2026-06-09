<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Align the customers table to the multi-tenant schema established in Sprint 3.
 *
 * The original prototype migration (2026_04_17_000005) created the table without
 * tenant_id — the column was never referenced by any live feature until now.
 * This migration adds the missing column as the FIRST column after `id`, matching
 * the convention used by all other tenant-scoped tables (branches, categories, etc.)
 *
 * Additive only: no existing columns are dropped or altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: 2026_05_15_000006_add_tenant_id_to_business_tables already adds
        // tenant_id to customers in a fresh migration run. Only add it if absent,
        // so this migration is safe on both fresh installs and existing environments.
        if (Schema::hasColumn('customers', 'tenant_id')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            // Add tenant_id as an indexed foreign key directly after `id`.
            // String (ULID) type matches the tenants.id type.
            $table->string('tenant_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('customers', 'tenant_id')) {
            return;
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
