<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Billing\Models\Subscription;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TenancySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPlans();
        $this->seedDemoTenant();
    }

    private function seedPlans(): void
    {
        $plans = [
            [
                'name'           => 'Free',
                'slug'           => 'free',
                'price_cents'    => 0,
                'billing_period' => 'monthly',
                'is_active'      => true,
                'sort_order'     => 1,
                'features'       => [
                    'Catalogo publico con hasta 20 productos',
                    'Carrito WhatsApp',
                    'POS basico',
                    'Hasta 50 pedidos por mes',
                    'Soporte por email',
                ],
                'limits' => [
                    'max_products'           => 20,
                    'max_orders_per_month'   => 50,
                    'max_users'              => 1,
                    'max_customers'          => 100,
                    'ocr_receipts_per_month' => 0,
                    'pdf_quotations'         => false,
                ],
            ],
            [
                'name'           => 'Pro',
                'slug'           => 'pro',
                'price_cents'    => 2900,
                'billing_period' => 'monthly',
                'is_active'      => true,
                'sort_order'     => 2,
                'features'       => [
                    'Catalogo publico ilimitado',
                    'Carrito WhatsApp + reservas',
                    'POS completo con inventario',
                    'Pedidos y despachos ilimitados',
                    'Gastos con OCR (50/mes)',
                    'Cotizaciones PDF',
                    'Dashboard con KPIs',
                    'Hasta 5 usuarios',
                    'Soporte prioritario',
                ],
                'limits' => [
                    'max_products'           => 500,
                    'max_orders_per_month'   => 1000,
                    'max_users'              => 5,
                    'max_customers'          => 2000,
                    'ocr_receipts_per_month' => 50,
                    'pdf_quotations'         => true,
                ],
            ],
            [
                'name'           => 'Enterprise',
                'slug'           => 'enterprise',
                'price_cents'    => 9900,
                'billing_period' => 'monthly',
                'is_active'      => true,
                'sort_order'     => 3,
                'features'       => [
                    'Todo lo de Pro, sin limites',
                    'Usuarios ilimitados',
                    'OCR ilimitado',
                    'Marca personalizada (colores + dominio propio)',
                    'Exportacion de datos (CSV / Excel)',
                    'API acceso (proxima version)',
                    'Gerente de cuenta dedicado',
                    'SLA 99.9%',
                ],
                'limits' => [
                    'max_products'           => null,
                    'max_orders_per_month'   => null,
                    'max_users'              => null,
                    'max_customers'          => null,
                    'ocr_receipts_per_month' => null,
                    'pdf_quotations'         => true,
                ],
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData
            );
        }

        $this->command->info('Plans seeded: Free, Pro, Enterprise');
    }

    private function seedDemoTenant(): void
    {
        $tenant = Tenant::withoutGlobalScopes()->firstOrCreate(
            ['slug' => 'demo'],
            [
                'id'           => (string) Str::ulid(),
                'name'         => 'Atelier Demo',
                'email'        => 'demo@eternova.app',
                'status'       => 'active',
                'brand_config' => [
                    'logo'            => null,
                    'primary_color'   => '#7c545d',
                    'secondary_color' => '#5a4b71',
                    'business_name'   => 'Atelier Demo',
                ],
                'locale_config' => [
                    'country'         => 'SV',
                    'currency'        => 'USD',
                    'currency_symbol' => '$',
                    'timezone'        => 'America/El_Salvador',
                    'phone_format'    => '#### ####',
                    'date_format'     => 'DD/MM/YYYY',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        // Attach demo tenant to the Pro plan with a trialing subscription
        $proPlan = Plan::where('slug', 'pro')->first();

        if ($proPlan !== null) {
            Subscription::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'plan_id' => $proPlan->id],
                [
                    'id'                   => (string) Str::ulid(),
                    'status'               => 'trialing',
                    'trial_ends_at'        => now()->addDays(30),
                    'current_period_start' => now(),
                    'current_period_end'   => now()->addDays(30),
                ]
            );
        }

        $this->command->info("Demo tenant seeded: {$tenant->id} (slug: demo)");
    }
}
