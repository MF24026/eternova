<?php

declare(strict_types=1);

namespace Database\Seeders\Expenses;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the 5 default expense categories for every demo tenant.
 *
 * Design decisions — mirrors ReservationsSeeder philosophy:
 *
 *   Raw DB inserts via DB::table():
 *     Bypasses BelongsToTenant::creating() (which requires currentTenant in the
 *     container), so we don't need to bind a tenant in the service container.
 *     Consistent with how DemoTenantsSeeder and other seeders handle raw inserts.
 *
 *   Idempotency:
 *     Each tenant+name pair is skipped individually (not the whole tenant) so a
 *     partial run or a new tenant added later can be re-seeded safely without
 *     duplicating existing rows. The UNIQUE(tenant_id, name) constraint would
 *     catch duplicates at the DB level anyway, but we guard at the PHP level to
 *     keep noise-free output.
 *
 *   No demo expenses here:
 *     Only default categories are seeded in E1. Actual expense rows with realistic
 *     amounts and OCR data are seeded in S6-E8 (ExpensesSeeder).
 *
 * Default category set (Spanish labels, matching the enum type buckets):
 *   Operación  → operating
 *   Productos  → products
 *   Nómina     → payroll
 *   Renta      → rent
 *   Otros      → other
 */
final class ExpenseCategoriesSeeder extends Seeder
{
    /**
     * Default categories provisioned for every new tenant.
     *
     * The name is what the tenant sees in the UI; type is the financial
     * reporting bucket used for aggregation in the monthly expense report.
     *
     * @var list<array{name: string, type: string}>
     */
    private const DEFAULT_CATEGORIES = [
        ['name' => 'Operación',  'type' => 'operating'],
        ['name' => 'Productos',  'type' => 'products'],
        ['name' => 'Nómina',     'type' => 'payroll'],
        ['name' => 'Renta',      'type' => 'rent'],
        ['name' => 'Otros',      'type' => 'other'],
    ];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $now = now()->toDateTimeString();
        $seeded = 0;
        $skipped = 0;

        foreach (self::DEFAULT_CATEGORIES as $category) {
            $exists = DB::table('expense_categories')
                ->where('tenant_id', $tenant->id)
                ->where('name', $category['name'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            DB::table('expense_categories')->insert([
                'tenant_id' => $tenant->id,
                'name' => $category['name'],
                'type' => $category['type'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $seeded++;
        }

        if ($skipped > 0) {
            $this->command->info(
                "ExpenseCategoriesSeeder: {$tenant->slug} — {$seeded} inserted, {$skipped} already existed (skipped).",
            );
        } else {
            $this->command->info(
                "ExpenseCategoriesSeeder: {$tenant->slug} — {$seeded} default categories seeded.",
            );
        }
    }
}
