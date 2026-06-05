<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Plans\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $monthly = $this->faker->randomElement([900, 1900, 2900, 4900]);

        return [
            'slug' => $this->faker->unique()->slug(2),
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'price_monthly_cents' => $monthly,
            // Yearly = 10 months (2 months free)
            'price_yearly_cents' => $monthly * 10,
            'currency' => 'USD',
            'features' => ['Feature A', 'Feature B'],
            'limits' => [
                'max_branches' => 1,
                'max_products' => 50,
                'max_users' => 3,
            ],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Basico plan — entry-level single-branch plan.
     */
    public function basico(): static
    {
        return $this->state(fn (array $attributes) => [
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
        ]);
    }

    /**
     * Pro plan — multi-branch with advanced features.
     */
    public function pro(): static
    {
        return $this->state(fn (array $attributes) => [
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
        ]);
    }

    /**
     * Enterprise plan — unlimited everything, dedicated onboarding.
     */
    public function enterprise(): static
    {
        return $this->state(fn (array $attributes) => [
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
            // null values mean unlimited — plan-gating code treats null as "no limit enforced"
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
        ]);
    }

    /**
     * Inactive plan — not shown on pricing page, cannot be subscribed to.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
