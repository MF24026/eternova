<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Inventory\Models\BranchInventory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Str;

/**
 * Seeds a small, ready-to-edit starter catalog for a freshly provisioned tenant
 * so the owner sees real data on first login instead of empty screens.
 *
 * The template is chosen during onboarding (business type). Each template adds a
 * handful of categories, products with variants, and healthy starting inventory
 * at the tenant's main branch. Everything is normal editable data — the owner
 * can rename, reprice or delete it.
 *
 * Prices are stored in the smallest unit of account (the `_cents` convention);
 * the values here are sensible USD-style amounts and can be edited per tenant.
 */
final class StarterCatalogService
{
    /** @var list<string> */
    public const TEMPLATES = ['floreria', 'accesorios', 'peluches', 'reposteria'];

    public const DEFAULT_TEMPLATE = 'floreria';

    private const STARTING_QUANTITY = 20;

    private const LOW_STOCK_ALERT = 5;

    private const GALLERY = [[
        'thumbnail' => 'https://placehold.co/200',
        'medium' => 'https://placehold.co/600',
        'full' => 'https://placehold.co/1200',
    ]];

    public static function isValidTemplate(?string $template): bool
    {
        return $template !== null && in_array($template, self::TEMPLATES, true);
    }

    /**
     * Seed the chosen template's catalog for the tenant's main branch.
     */
    public function seed(Tenant $tenant, ?string $template = null): void
    {
        $template = self::isValidTemplate($template) ? $template : self::DEFAULT_TEMPLATE;

        $branch = Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->first();

        // Bind the tenant so BelongsToTenant stamps tenant_id on every create.
        app()->instance('currentTenant', $tenant);

        try {
            $definition = $this->definition($template);
            $categoryIds = $this->createCategories($definition['categories']);
            $this->createProducts($definition['products'], $categoryIds, $branch);
        } finally {
            app()->forgetInstance('currentTenant');
        }
    }

    /**
     * @param  list<array{name: string, slug: string}>  $categories
     * @return array<string, int|string> slug => id
     */
    private function createCategories(array $categories): array
    {
        $ids = [];

        foreach ($categories as $position => $category) {
            $row = Category::create([
                'name' => $category['name'],
                'slug' => $category['slug'],
                'sort_order' => $position,
            ]);
            $ids[$category['slug']] = $row->id;
        }

        return $ids;
    }

    /**
     * @param  list<array{name: string, sku: string, price: int, featured?: bool, categories: list<string>, variants: list<array{label: string, price: int}>}>  $products
     * @param  array<string, int|string>  $categoryIds
     */
    private function createProducts(array $products, array $categoryIds, ?Branch $branch): void
    {
        foreach ($products as $definition) {
            $product = Product::create([
                'name' => $definition['name'],
                'slug' => Str::slug($definition['name']),
                'sku_root' => $definition['sku'],
                'description' => null,
                'base_price_cents' => $definition['price'],
                'gallery' => self::GALLERY,
                'is_active' => true,
                'is_featured' => $definition['featured'] ?? false,
            ]);

            $linkIds = collect($definition['categories'])
                ->map(fn (string $slug) => $categoryIds[$slug] ?? null)
                ->filter()
                ->values()
                ->all();

            if ($linkIds !== []) {
                $product->categories()->syncWithoutDetaching($linkIds);
            }

            foreach ($definition['variants'] as $position => $variant) {
                $row = ProductVariant::create([
                    'product_id' => $product->id,
                    'sku' => $definition['sku'].'-'.strtoupper(Str::slug($variant['label'])),
                    'price_cents' => $variant['price'],
                    'min_stock_alert' => self::LOW_STOCK_ALERT,
                    'options' => ['Presentacion' => $variant['label']],
                    'position' => $position,
                ]);

                if ($branch !== null) {
                    BranchInventory::create([
                        'branch_id' => $branch->id,
                        'product_variant_id' => $row->id,
                        'quantity' => self::STARTING_QUANTITY,
                    ]);
                }
            }
        }
    }

    /**
     * @return array{categories: list<array{name: string, slug: string}>, products: list<array{name: string, sku: string, price: int, featured?: bool, categories: list<string>, variants: list<array{label: string, price: int}>}>}
     */
    private function definition(string $template): array
    {
        return match ($template) {
            'accesorios' => $this->accesorios(),
            'peluches' => $this->peluches(),
            'reposteria' => $this->reposteria(),
            default => $this->floreria(),
        };
    }

    /** @return array{categories: list<array{name: string, slug: string}>, products: list<array<string, mixed>>} */
    private function floreria(): array
    {
        return [
            'categories' => [
                ['name' => 'Arreglos florales', 'slug' => 'arreglos-florales'],
                ['name' => 'Rosas eternas',     'slug' => 'rosas-eternas'],
                ['name' => 'Bouquets',          'slug' => 'bouquets'],
                ['name' => 'Regalos',           'slug' => 'regalos'],
            ],
            'products' => [
                ['name' => 'Rosa Eterna Clasica', 'sku' => 'REC', 'price' => 2500, 'featured' => true, 'categories' => ['rosas-eternas'], 'variants' => [
                    ['label' => 'Pequeno', 'price' => 2500], ['label' => 'Mediano', 'price' => 3500], ['label' => 'Grande', 'price' => 5000],
                ]],
                ['name' => 'Bouquet de Novia Aurora', 'sku' => 'BNA', 'price' => 4500, 'featured' => true, 'categories' => ['bouquets'], 'variants' => [
                    ['label' => 'Clasico', 'price' => 4500], ['label' => 'Premium', 'price' => 7500],
                ]],
                ['name' => 'Ramo de Girasoles', 'sku' => 'RGI', 'price' => 3000, 'categories' => ['arreglos-florales'], 'variants' => [
                    ['label' => '6 tallos', 'price' => 3000], ['label' => '12 tallos', 'price' => 5500],
                ]],
                ['name' => 'Centro de Mesa Romantico', 'sku' => 'CMR', 'price' => 6000, 'categories' => ['arreglos-florales'], 'variants' => [
                    ['label' => 'Pequeno', 'price' => 6000], ['label' => 'Grande', 'price' => 9000],
                ]],
                ['name' => 'Caja de Rosas Preservadas', 'sku' => 'CRP', 'price' => 5500, 'featured' => true, 'categories' => ['rosas-eternas'], 'variants' => [
                    ['label' => '9 rosas', 'price' => 5500], ['label' => '25 rosas', 'price' => 9500],
                ]],
                ['name' => 'Tarjeta y Mini Bouquet', 'sku' => 'TMB', 'price' => 1500, 'categories' => ['regalos'], 'variants' => [
                    ['label' => 'Unica', 'price' => 1500],
                ]],
            ],
        ];
    }

    /** @return array{categories: list<array{name: string, slug: string}>, products: list<array<string, mixed>>} */
    private function accesorios(): array
    {
        return [
            'categories' => [
                ['name' => 'Bisuteria',        'slug' => 'bisuteria'],
                ['name' => 'Bolsos',           'slug' => 'bolsos'],
                ['name' => 'Sets de regalo',   'slug' => 'sets-regalo'],
                ['name' => 'Decoracion',       'slug' => 'decoracion'],
            ],
            'products' => [
                ['name' => 'Collar Minimalista Plata', 'sku' => 'CMP', 'price' => 1800, 'featured' => true, 'categories' => ['bisuteria'], 'variants' => [
                    ['label' => 'Corto', 'price' => 1800], ['label' => 'Largo', 'price' => 2200],
                ]],
                ['name' => 'Aretes Perla Clasicos', 'sku' => 'APC', 'price' => 1200, 'categories' => ['bisuteria'], 'variants' => [
                    ['label' => 'Unico', 'price' => 1200],
                ]],
                ['name' => 'Bolso Tote de Lona', 'sku' => 'BTL', 'price' => 2500, 'featured' => true, 'categories' => ['bolsos'], 'variants' => [
                    ['label' => 'Beige', 'price' => 2500], ['label' => 'Negro', 'price' => 2500],
                ]],
                ['name' => 'Billetera de Cuero PU', 'sku' => 'BCP', 'price' => 2000, 'categories' => ['bolsos'], 'variants' => [
                    ['label' => 'Cafe', 'price' => 2000], ['label' => 'Negro', 'price' => 2000],
                ]],
                ['name' => 'Set de Regalo Spa', 'sku' => 'SRS', 'price' => 3500, 'featured' => true, 'categories' => ['sets-regalo'], 'variants' => [
                    ['label' => 'Basico', 'price' => 3500], ['label' => 'Deluxe', 'price' => 5500],
                ]],
                ['name' => 'Vela Aromatica Lavanda', 'sku' => 'VAL', 'price' => 1500, 'categories' => ['decoracion'], 'variants' => [
                    ['label' => '100g', 'price' => 1500], ['label' => '250g', 'price' => 2800],
                ]],
            ],
        ];
    }

    /** @return array{categories: list<array{name: string, slug: string}>, products: list<array<string, mixed>>} */
    private function peluches(): array
    {
        return [
            'categories' => [
                ['name' => 'Peluches',        'slug' => 'peluches'],
                ['name' => 'Globos',          'slug' => 'globos'],
                ['name' => 'Cajas sorpresa',  'slug' => 'cajas-sorpresa'],
                ['name' => 'Detalles',        'slug' => 'detalles'],
            ],
            'products' => [
                ['name' => 'Oso de Peluche Clasico', 'sku' => 'OPC', 'price' => 1500, 'featured' => true, 'categories' => ['peluches'], 'variants' => [
                    ['label' => 'Pequeno', 'price' => 1500], ['label' => 'Mediano', 'price' => 2500], ['label' => 'Grande', 'price' => 3500],
                ]],
                ['name' => 'Conejo Suave Pastel', 'sku' => 'CSP', 'price' => 1800, 'categories' => ['peluches'], 'variants' => [
                    ['label' => 'Unico', 'price' => 1800],
                ]],
                ['name' => 'Globo Metalico de Numero', 'sku' => 'GMN', 'price' => 800, 'categories' => ['globos'], 'variants' => [
                    ['label' => 'Sin helio', 'price' => 800], ['label' => 'Con helio', 'price' => 1200],
                ]],
                ['name' => 'Caja Sorpresa', 'sku' => 'CSO', 'price' => 4000, 'featured' => true, 'categories' => ['cajas-sorpresa'], 'variants' => [
                    ['label' => 'Mediana', 'price' => 4000], ['label' => 'Grande', 'price' => 6500],
                ]],
                ['name' => 'Ramo de Peluches', 'sku' => 'RDP', 'price' => 5000, 'featured' => true, 'categories' => ['detalles'], 'variants' => [
                    ['label' => '3 peluches', 'price' => 5000], ['label' => '5 peluches', 'price' => 7500],
                ]],
                ['name' => 'Tarjeta Pop-up', 'sku' => 'TPU', 'price' => 600, 'categories' => ['detalles'], 'variants' => [
                    ['label' => 'Unica', 'price' => 600],
                ]],
            ],
        ];
    }

    /** @return array{categories: list<array{name: string, slug: string}>, products: list<array<string, mixed>>} */
    private function reposteria(): array
    {
        return [
            'categories' => [
                ['name' => 'Tortas',     'slug' => 'tortas'],
                ['name' => 'Cupcakes',   'slug' => 'cupcakes'],
                ['name' => 'Chocolates', 'slug' => 'chocolates'],
                ['name' => 'Galletas',   'slug' => 'galletas'],
            ],
            'products' => [
                ['name' => 'Torta Personalizada', 'sku' => 'TPE', 'price' => 3500, 'featured' => true, 'categories' => ['tortas'], 'variants' => [
                    ['label' => '6 porciones', 'price' => 3500], ['label' => '12 porciones', 'price' => 6000], ['label' => '20 porciones', 'price' => 9500],
                ]],
                ['name' => 'Cupcakes Decorados', 'sku' => 'CUD', 'price' => 1200, 'featured' => true, 'categories' => ['cupcakes'], 'variants' => [
                    ['label' => 'Caja de 6', 'price' => 1200], ['label' => 'Caja de 12', 'price' => 2200],
                ]],
                ['name' => 'Caja de Chocolates', 'sku' => 'CDC', 'price' => 2000, 'featured' => true, 'categories' => ['chocolates'], 'variants' => [
                    ['label' => '9 piezas', 'price' => 2000], ['label' => '16 piezas', 'price' => 3200],
                ]],
                ['name' => 'Galletas Decoradas', 'sku' => 'GDE', 'price' => 1500, 'categories' => ['galletas'], 'variants' => [
                    ['label' => 'Caja de 6', 'price' => 1500], ['label' => 'Caja de 12', 'price' => 2800],
                ]],
                ['name' => 'Mini Postres Surtidos', 'sku' => 'MPS', 'price' => 2500, 'categories' => ['cupcakes'], 'variants' => [
                    ['label' => 'Bandeja', 'price' => 2500],
                ]],
                ['name' => 'Tarjeta y Brownie', 'sku' => 'TBR', 'price' => 800, 'categories' => ['chocolates'], 'variants' => [
                    ['label' => 'Unico', 'price' => 800],
                ]],
            ],
        ];
    }
}
