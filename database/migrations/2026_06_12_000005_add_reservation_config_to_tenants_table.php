<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-tenant reservation configuration columns to the tenants table.
 *
 * These columns allow each tenant to customise their reservations workflow
 * without depending on the Settings module (which lands in Sprint 7).
 *
 *   reservation_deposit_pct — default % of total_cents required as deposit
 *     at confirmation. Staff can override per-reservation; this is only the
 *     starting default. Range 0–100, stored as tinyint (1 byte, plenty).
 *
 *   reservation_occasions — JSON array of occasion labels the tenant offers
 *     (e.g. ["Boda", "Corporativo", "Quinceañera"]). Null means the system
 *     will use a hardcoded default list. Stored as JSON; cast to array in model.
 *
 * Both columns are additive — existing tenants get sensible defaults and are
 * not affected operationally by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedTinyInteger('reservation_deposit_pct')
                ->default(30)
                ->after('locale_extra')
                ->comment('Default deposit % for new reservations; overridable per reservation.');

            $table->json('reservation_occasions')
                ->nullable()
                ->after('reservation_deposit_pct')
                ->comment('Tenant-specific list of occasion labels. Null = use system defaults.');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['reservation_deposit_pct', 'reservation_occasions']);
        });
    }
};
