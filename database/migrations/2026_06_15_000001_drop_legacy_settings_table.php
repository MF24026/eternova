<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the prototype-era `settings` table so the Settings module can be built
 * on a correct multi-tenant foundation.
 *
 * The legacy table (2026_04_17_000002) is broken and unused:
 *   - No tenant_id — the Setting model used BelongsToTenant but the column never existed.
 *   - unique(group, key) is GLOBAL — two tenants could not share a setting key.
 *   - Setting::get()/set() ignored tenant entirely.
 *   - No route ever wired it (routes/api/v1/settings.php was an empty stub).
 *
 * Per-tenant config lives on the `tenants` row (brand, locale, quotation/reservation
 * defaults). Per-branch execution settings move to the new `branch_settings` table
 * (2026_06_15_000002). Mirrors the drop-legacy approach used for orders, reservations
 * and quotations.
 *
 * Greenfield — no production data. Safe to run multiple times (guarded).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('settings');
    }

    public function down(): void
    {
        // Recreate the legacy shape so the migration is reversible. This table is
        // intentionally never used again — branch_settings replaces it.
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group', 50)->index();
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->enum('type', ['string', 'integer', 'boolean', 'json', 'file'])->default('string');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }
};
