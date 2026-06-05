<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters:
     *   1. ReservedSubdomainsSeeder — platform slug blocklist (no deps)
     *   2. PlansSeeder              — subscription plans (needed by provisioner)
     *   3. SuperAdminUserSeeder     — platform super-admin user (no tenant deps)
     *   4. DemoTenantsSeeder        — demo tenants, branches, subscriptions and staff
     */
    public function run(): void
    {
        $this->call([
            ReservedSubdomainsSeeder::class,
            PlansSeeder::class,
            SuperAdminUserSeeder::class,
            DemoTenantsSeeder::class,
        ]);
    }
}
