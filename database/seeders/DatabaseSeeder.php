<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Catalog\CategoriesSeeder;
use Database\Seeders\Catalog\ProductsSeeder;
use Database\Seeders\Catalog\TagsSeeder;
use Database\Seeders\Expenses\ExpenseCategoriesSeeder;
use Database\Seeders\Expenses\ExpensesSeeder;
use Database\Seeders\Inventory\BranchInventorySeeder;
use Database\Seeders\Inventory\InventoryMovementsSeeder;
use Database\Seeders\Orders\OrdersSeeder;
use Database\Seeders\Quotations\QuotationsSeeder;
use Database\Seeders\Reservations\ReservationsSeeder;
use Database\Seeders\Settings\BranchSettingsSeeder;
use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters — each step depends on the previous:
     *   1. ReservedSubdomainsSeeder      — platform slug blocklist (no deps)
     *   2. PlansSeeder                   — subscription plans (needed by provisioner)
     *   3. SuperAdminUserSeeder          — platform super-admin user (no tenant deps)
     *   4. DemoTenantsSeeder             — demo tenants, branches, subscriptions and staff
     *   5. Catalog\CategoriesSeeder      — category trees per demo tenant
     *   6. Catalog\TagsSeeder            — standard tags per demo tenant
     *   7. Catalog\ProductsSeeder        — ~20 products per tenant with variants
     *   8. Inventory\BranchInventorySeeder   — initial stock snapshot per variant/branch
     *   9. Inventory\InventoryMovementsSeeder — historical movement ledger (last 30 days)
     *  10. Orders\OrdersSeeder               — demo orders with items + status timelines
     *  11. Reservations\ReservationsSeeder   — demo reservations with payments + timelines
     *  12. Expenses\ExpenseCategoriesSeeder  — 5 default categories per demo tenant
     *  13. Expenses\ExpensesSeeder           — ~30 demo expenses per tenant (OCR states, 5 months)
     *  14. Quotations\QuotationsSeeder       — ~10 demo quotations per tenant (all five statuses, line items, timelines)
     *  15. Settings\BranchSettingsSeeder     — tenant-default contact + tax settings per demo tenant
     */
    public function run(): void
    {
        $this->call([
            ReservedSubdomainsSeeder::class,
            PlansSeeder::class,
            SuperAdminUserSeeder::class,
            DemoTenantsSeeder::class,
            CategoriesSeeder::class,
            TagsSeeder::class,
            ProductsSeeder::class,
            BranchInventorySeeder::class,
            InventoryMovementsSeeder::class,
            OrdersSeeder::class,
            ReservationsSeeder::class,
            ExpenseCategoriesSeeder::class,
            ExpensesSeeder::class,
            QuotationsSeeder::class,
            BranchSettingsSeeder::class,
        ]);
    }
}
