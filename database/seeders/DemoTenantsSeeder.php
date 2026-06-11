<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Auth\Services\TenantProvisioner;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

final class DemoTenantsSeeder extends Seeder
{
    public function run(): void
    {
        /** @var TenantProvisioner $provisioner */
        $provisioner = app(TenantProvisioner::class);

        $this->seedRosaEterna($provisioner);
        $this->seedRegalosTatiana($provisioner);

        $this->command->info(
            'DemoTenantsSeeder: 2 tenants seeded with 7 users total (2 owners + 5 staff).',
        );
    }

    private function seedRosaEterna(TenantProvisioner $provisioner): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'caro@rosaeterna.com'],
            [
                'name' => 'Carolina Rosa',
                'password' => Hash::make('DemoPro123!'),
                'email_verified_at' => now(),
            ],
        );

        $existing = Tenant::findBySlug('rosa-eterna');

        if ($existing !== null) {
            $this->command->info('DemoTenantsSeeder: tenant rosa-eterna already exists, skipping.');
            $tenant = $existing;
        } else {
            $tenant = $provisioner->provision($owner, [
                'slug' => 'rosa-eterna',
                'name' => 'Floristeria Rosa Eterna',
                'business_name' => 'Floreria Rosa Eterna',
                'country_code' => 'SV',
                'currency' => 'USD',
                'language' => 'es',
                'timezone' => 'America/El_Salvador',
                'plan_slug' => 'pro',
            ]);

            $this->command->info("DemoTenantsSeeder: tenant rosa-eterna provisioned ({$tenant->id}).");
        }

        // Brand colours — rosa-eterna keeps the Ethereal Boutique mauve/rose identity.
        // Set explicitly (not null) so the storefront themes deterministically.
        $tenant->update([
            'primary_color' => '#7c545d',
            'secondary_color' => '#5a4b71',
        ]);

        // Ensure brand_extra has whatsapp_number for the storefront checkout button.
        $this->mergeIntoTenantBrandExtra($tenant, [
            'whatsapp_number' => '50370001234',
            'tagline' => 'Flores que hablan por ti',
        ]);

        $this->attachStaff($tenant, [
            ['email' => 'lucia@rosaeterna.com', 'name' => 'Lucia Martinez', 'role' => 'staff'],
            ['email' => 'daniel@rosaeterna.com', 'name' => 'Daniel Garcia', 'role' => 'staff'],
            ['email' => 'sofia@rosaeterna.com', 'name' => 'Sofia Lopez', 'role' => 'staff'],
        ]);

        $this->command->info('DemoTenantsSeeder: rosa-eterna staff attached (3 users).');
    }

    private function seedRegalosTatiana(TenantProvisioner $provisioner): void
    {
        $owner = User::firstOrCreate(
            ['email' => 'tati@regalostatiana.com'],
            [
                'name' => 'Tatiana Hernandez',
                'password' => Hash::make('DemoBasic123!'),
                'email_verified_at' => now(),
            ],
        );

        $existing = Tenant::findBySlug('tatiana');

        if ($existing !== null) {
            $this->command->info('DemoTenantsSeeder: tenant tatiana already exists, skipping.');
            $tenant = $existing;
        } else {
            $tenant = $provisioner->provision($owner, [
                'slug' => 'tatiana',
                'name' => 'Regalos Tatiana',
                'business_name' => 'Regalos Tatiana',
                'country_code' => 'CO',
                'currency' => 'COP',
                'language' => 'es',
                'timezone' => 'America/Bogota',
                'plan_slug' => 'basico',
            ]);

            $this->command->info("DemoTenantsSeeder: tenant tatiana provisioned ({$tenant->id}).");
        }

        // Brand colours — tatiana uses a distinct teal/terracotta palette so the
        // multi-tenant storefront theming is visibly different from rosa-eterna.
        $tenant->update([
            'primary_color' => '#2f7d72',
            'secondary_color' => '#b5642f',
        ]);

        // Ensure brand_extra has whatsapp_number for the storefront checkout button.
        $this->mergeIntoTenantBrandExtra($tenant, [
            'whatsapp_number' => '573001234567',
            'tagline' => 'Regalos que llegan al corazon',
        ]);

        $this->attachStaff($tenant, [
            ['email' => 'maria@regalostatiana.com', 'name' => 'Maria Rojas', 'role' => 'staff'],
            ['email' => 'juan@regalostatiana.com', 'name' => 'Juan Restrepo', 'role' => 'admin'],
        ]);

        $this->command->info('DemoTenantsSeeder: tatiana staff attached (2 users).');
    }

    /**
     * Merge new keys into a tenant's brand_extra JSON column without clobbering
     * values that were already set by TenantProvisioner or previous seeder runs.
     *
     * Safe to call multiple times — only absent keys are added.
     *
     * @param  array<string, mixed>  $extra
     */
    private function mergeIntoTenantBrandExtra(Tenant $tenant, array $extra): void
    {
        /** @var array<string, mixed> $current */
        $current = $tenant->brand_extra ?? [];

        $merged = array_merge($extra, $current);

        if ($merged !== $current) {
            $tenant->update(['brand_extra' => $merged]);
        }
    }

    /**
     * Create staff users (if not yet existing) and attach them to the given tenant.
     *
     * Uses syncWithoutDetaching so re-running the seeder never duplicates pivot rows.
     *
     * @param  list<array{email: string, name: string, role: string}>  $staffDefinitions
     */
    private function attachStaff(Tenant $tenant, array $staffDefinitions): void
    {
        $pivotRows = [];

        foreach ($staffDefinitions as $definition) {
            $user = User::firstOrCreate(
                ['email' => $definition['email']],
                [
                    'name' => $definition['name'],
                    'password' => Hash::make('DemoStaff123!'),
                    'email_verified_at' => now(),
                ],
            );

            $pivotRows[$user->id] = [
                'role' => $definition['role'],
                'joined_at' => now(),
            ];
        }

        $tenant->users()->syncWithoutDetaching($pivotRows);
    }
}
