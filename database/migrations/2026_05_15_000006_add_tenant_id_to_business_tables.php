<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Tables that belong to a tenant and need the tenant_id column.
     *
     * Order matters for the down() rollback: child tables first (no FK deps on each other here,
     * but keeping them logically grouped makes auditing easier).
     *
     * @var list<string>
     */
    private array $businessTables = [
        'settings',
        'categories',
        'products',
        'customers',
        'inventory_movements',
        'orders',
        'order_items',
        'reservations',
        'reservation_payments',
        'expense_categories',
        'expenses',
        'quotations',
        'quotation_items',
    ];

    /**
     * Strategy:
     *  1. Ensure a "demo" tenant exists (used as default owner of all pre-existing data).
     *  2. Add tenant_id as NULLABLE with FK first (allows INSERT on existing rows).
     *  3. Backfill every row with the demo tenant id.
     *  4. Change to NOT NULL.
     *
     * This avoids dropping/re-creating FKs and is safe for tables that already have data.
     */
    public function up(): void
    {
        // Step 1 — guarantee the demo tenant exists before we reference it
        $demoTenant = Tenant::withoutGlobalScopes()->firstOrCreate(
            ['slug' => 'demo'],
            [
                'id'           => (string) Str::ulid(),
                'name'         => 'Atelier Demo',
                'email'        => 'demo@eternova.app',
                'status'       => 'active',
                'brand_config' => [
                    'logo'          => null,
                    'primary_color' => '#7c545d',
                ],
                'locale_config' => [
                    'country'  => 'SV',
                    'currency' => 'USD',
                    'timezone' => 'America/El_Salvador',
                    'phone_format' => '#### ####',
                ],
            ]
        );

        $demoTenantId = $demoTenant->id;

        foreach ($this->businessTables as $table) {
            // Step 2 — add nullable column + FK
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('tenant_id', 26)
                    ->nullable()
                    ->after('id');

                $blueprint->foreign('tenant_id')
                    ->references('id')
                    ->on('tenants')
                    ->cascadeOnDelete();

                $blueprint->index('tenant_id');
            });

            // Step 3 — backfill existing rows
            DB::table($table)->whereNull('tenant_id')->update(['tenant_id' => $demoTenantId]);

            // Step 4 — make NOT NULL
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('tenant_id', 26)->nullable(false)->change();
            });
        }
    }

    /**
     * Remove tenant_id FK and column from all business tables.
     */
    public function down(): void
    {
        foreach (array_reverse($this->businessTables) as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['tenant_id']);
                $blueprint->dropIndex(['tenant_id']);
                $blueprint->dropColumn('tenant_id');
            });
        }
    }
};
