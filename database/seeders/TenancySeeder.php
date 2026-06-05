<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Billing\Services\SubscriptionService;
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
        // Column names match the Plan model: price_monthly_cents, price_yearly_cents, currency.
        // Legacy columns (price_cents, billing_period) were removed in the Plan model schema.
        $plans = [
            [
                'name' => 'Basico',
                'slug' => 'basico',
                'description' => 'Para negocios pequenos que estan empezando.',
                'price_monthly_cents' => 0,
                'price_yearly_cents' => 0,
                'currency' => 'USD',
                'is_active' => true,
                'sort_order' => 1,
                'features' => [
                    'Catalogo publico con hasta 20 productos',
                    'Carrito WhatsApp',
                    'POS basico',
                    'Hasta 50 pedidos por mes',
                    'Soporte por email',
                ],
                'limits' => [
                    'max_products' => 20,
                    'max_orders_per_month' => 50,
                    'max_users' => 1,
                    'max_customers' => 100,
                    'ocr_receipts_per_month' => 0,
                    'pdf_quotations' => false,
                ],
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Para negocios en crecimiento.',
                'price_monthly_cents' => 2900,
                'price_yearly_cents' => 29000,
                'currency' => 'USD',
                'is_active' => true,
                'sort_order' => 2,
                'features' => [
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
                    'max_products' => 500,
                    'max_orders_per_month' => 1000,
                    'max_users' => 5,
                    'max_customers' => 2000,
                    'ocr_receipts_per_month' => 50,
                    'pdf_quotations' => true,
                ],
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'description' => 'Sin limites para grandes operaciones.',
                'price_monthly_cents' => 9900,
                'price_yearly_cents' => 99000,
                'currency' => 'USD',
                'is_active' => true,
                'sort_order' => 3,
                'features' => [
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
                    'max_products' => null,
                    'max_orders_per_month' => null,
                    'max_users' => null,
                    'max_customers' => null,
                    'ocr_receipts_per_month' => null,
                    'pdf_quotations' => true,
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
        // Column names updated for issue #6 (S0-E2): hybrid schema replaces JSON blobs.
        $tenant = Tenant::withoutGlobalScopes()->firstOrCreate(
            ['slug' => 'demo'],
            [
                'id' => (string) Str::ulid(),
                'name' => 'Atelier Demo',
                'business_name' => 'Atelier Demo',
                'email' => 'demo@eternova.app',
                'status' => 'active',
                'primary_color' => '#7c545d',
                'secondary_color' => '#5a4b71',
                'currency' => 'USD',
                'country_code' => 'SV',
                'timezone' => 'America/El_Salvador',
                'locale_extra' => [
                    'currency_symbol' => '$',
                    'phone_format' => '#### ####',
                    'date_format' => 'DD/MM/YYYY',
                ],
                'trial_ends_at' => now()->addDays(30),
            ]
        );

        // Attach demo tenant to the Pro plan with a trialing subscription (idempotent)
        $proPlan = Plan::where('slug', 'pro')->where('is_active', true)->first();

        if ($proPlan !== null && ! $tenant->subscriptions()->exists()) {
            /** @var SubscriptionService $subscriptionService */
            $subscriptionService = app(SubscriptionService::class);
            $subscriptionService->create($tenant, $proPlan);
        }

        $this->command->info("Demo tenant seeded: {$tenant->id} (slug: demo)");
    }
}
