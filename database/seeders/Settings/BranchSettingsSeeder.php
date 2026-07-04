<?php

declare(strict_types=1);

namespace Database\Seeders\Settings;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds realistic tenant-default rows (branch_id = null) in branch_settings so
 * the demo tenants show populated Contacto / Impuestos settings out of the box.
 *
 * Orders + Notifications are intentionally left to their coded defaults (the
 * resolver returns them) — seeding them would add noise without value.
 *
 * Direct DB inserts (mirrors OrdersSeeder / QuotationsSeeder): bypasses the
 * BelongsToTenant tenant-context requirement. `value` is a json column, so each
 * value is json_encoded.
 *
 * Idempotent: a tenant is skipped if it already has any branch_settings rows.
 */
final class BranchSettingsSeeder extends Seeder
{
    /**
     * Per-tenant execution settings keyed by slug.
     *
     * @var array<string, array<string, array<string, mixed>>>
     */
    private const TENANT_SETTINGS = [
        'rosa-eterna' => [
            'contact' => [
                'phone' => '+503 7892-1234',
                'email' => 'hola@rosaeterna.sv',
                'website' => 'rosaeterna.sv',
                'address' => 'Col. Escalón, Calle La Mascota #25, San Salvador',
            ],
            'tax' => [
                'enabled' => true,
                'rate_bps' => 1300,
                'id_label' => 'NIT',
                'id_number' => '0614-120385-101-2',
            ],
        ],
        'tatiana' => [
            'contact' => [
                'phone' => '+503 7456-9871',
                'email' => 'contacto@detallestatiana.sv',
                'website' => 'detallestatiana.sv',
                'address' => 'Av. Las Magnolias #14, Santa Tecla',
            ],
            'tax' => [
                'enabled' => true,
                'rate_bps' => 1300,
                'id_label' => 'NIT',
                'id_number' => '0511-250790-102-3',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $alreadySeeded = DB::table('branch_settings')
            ->where('tenant_id', $tenant->id)
            ->exists();

        if ($alreadySeeded) {
            $this->command->info("BranchSettingsSeeder: settings already exist for {$tenant->slug}, skipping.");

            return;
        }

        $groups = self::TENANT_SETTINGS[$tenant->slug] ?? null;

        if ($groups === null) {
            $this->command->info("BranchSettingsSeeder: no demo settings for {$tenant->slug}, leaving coded defaults.");

            return;
        }

        $now = now()->toDateTimeString();
        $rows = [];

        foreach ($groups as $group => $values) {
            foreach ($values as $key => $value) {
                $rows[] = [
                    'tenant_id' => $tenant->id,
                    'branch_id' => null,   // tenant default
                    'group' => $group,
                    'key' => $key,
                    'value' => json_encode($value),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('branch_settings')->insert($rows);

        $this->command->info(
            "BranchSettingsSeeder: {$tenant->slug} — ".count($rows).' default settings seeded (contact + tax).',
        );
    }
}
