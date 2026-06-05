<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class SuperAdminUserSeeder extends Seeder
{
    /**
     * Create or update the platform-level super-admin user.
     *
     * The password below is intentionally weak and dev-only.
     * Change it immediately via the super-admin panel before any staging or
     * production deployment.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@eternova.app'],
            [
                'name' => 'Eternova Super Admin',
                'password' => Hash::make('ChangeMe123!'),
                'is_super_admin' => true,
                'email_verified_at' => now(),
            ],
        );

        $this->command->info('SuperAdminUserSeeder: super-admin (admin@eternova.app) created/updated.');
    }
}
