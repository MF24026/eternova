<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Plans\Models\Plan;
use Illuminate\Database\Seeder;

final class PlansSeeder extends Seeder
{
    /**
     * Seed the three canonical Eternova plans.
     *
     * These plans are the foundation of the SaaS pricing model. Any change to
     * slugs or limit keys requires coordinated updates in plan-gating code.
     *
     * Yearly price = monthly * 10 (2 months free for annual commitment).
     * null in limits = unlimited — plan-gating code must treat null as "no limit enforced".
     */
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'basico',
                'name' => 'Basico',
                'description' => 'Plan de entrada para negocios pequenos con una sola sucursal.',
                'price_monthly_cents' => 900,
                'price_yearly_cents' => 9000,
                'currency' => 'USD',
                'features' => [
                    '1 sucursal',
                    'Catalogo publico con WhatsApp',
                    'POS basico',
                    'Hasta 50 productos',
                    'Reportes mensuales',
                ],
                'limits' => [
                    'max_branches' => 1,
                    'max_products' => 50,
                    'max_users' => 3,
                    'max_orders_per_month' => 200,
                    'pdf_quotations' => false,
                    'ocr_receipts_per_month' => 0,
                    'custom_domain' => false,
                ],
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'slug' => 'pro',
                'name' => 'Pro',
                'description' => 'Para negocios en crecimiento con multiples sucursales y flujos avanzados.',
                'price_monthly_cents' => 2900,
                'price_yearly_cents' => 29000,
                'currency' => 'USD',
                'features' => [
                    'Hasta 3 sucursales',
                    'Catalogo + WhatsApp + checkout web',
                    'POS completo con inventario en tiempo real',
                    'Reservas con adelantos',
                    'Cotizaciones PDF',
                    'Gastos con OCR (50/mes)',
                    'Dashboard con metricas',
                    'Soporte prioritario',
                ],
                'limits' => [
                    'max_branches' => 3,
                    'max_products' => 1000,
                    'max_users' => 10,
                    'max_orders_per_month' => 5000,
                    'pdf_quotations' => true,
                    'ocr_receipts_per_month' => 50,
                    'custom_domain' => false,
                ],
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'slug' => 'enterprise',
                'name' => 'Enterprise',
                'description' => 'Para cadenas y franquicias: sucursales ilimitadas, SLA 99.9%, custom domain.',
                'price_monthly_cents' => 9900,
                'price_yearly_cents' => 99000,
                'currency' => 'USD',
                'features' => [
                    'Sucursales ilimitadas',
                    'Todo lo de Pro',
                    'Custom domain con SSL',
                    'API access para integraciones',
                    'OCR ilimitado',
                    'Multi-pasarela de pago',
                    'SLA 99.9%',
                    'Onboarding dedicado',
                ],
                // null values = unlimited — plan-gating reads null as "no limit enforced"
                'limits' => [
                    'max_branches' => null,
                    'max_products' => null,
                    'max_users' => null,
                    'max_orders_per_month' => null,
                    'pdf_quotations' => true,
                    'ocr_receipts_per_month' => null,
                    'custom_domain' => true,
                ],
                'is_active' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(
                ['slug' => $planData['slug']],
                $planData,
            );
        }
    }
}
