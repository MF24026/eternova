<?php

declare(strict_types=1);

namespace Database\Seeders\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Seeds ~20 realistic products per demo tenant, each with 2–3 explicit variants.
 *
 * Pricing convention:
 *   rosa-eterna (USD):  base_price_cents stores US cents — e.g. 2500 = $25.00 USD.
 *   tatiana (COP):      base_price_cents stores the full COP amount as an integer — there
 *                       are no "centavos" in everyday COP usage, so 50000 = COP $50,000.
 *                       The column name is _cents because the schema is currency-agnostic;
 *                       what goes in is "the smallest unit of account" which for COP is 1.
 *
 * Gallery: placeholder images only — no real uploads in seeder context.
 * Idempotency: keyed on (tenant_id, slug). Re-running db:seed is safe.
 */
final class ProductsSeeder extends Seeder
{
    /** Placeholder gallery attached to every seeded product. */
    private const GALLERY = [
        [
            'thumbnail' => 'https://placehold.co/200',
            'medium' => 'https://placehold.co/600',
            'full' => 'https://placehold.co/1200',
        ],
    ];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);

        match ($tenant->slug) {
            'rosa-eterna' => $this->seedRosaEterna($tenant),
            'tatiana' => $this->seedTatiana($tenant),
            default => null,
        };

        app()->forgetInstance('currentTenant');
    }

    // -------------------------------------------------------------------------
    // rosa-eterna products (USD, florist)
    // -------------------------------------------------------------------------

    private function seedRosaEterna(Tenant $tenant): void
    {
        $categories = Category::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('slug');

        $tags = Tag::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('slug');

        /**
         * @var list<array{
         *   name: string,
         *   sku_root: string,
         *   base_price_cents: int,
         *   is_featured: bool,
         *   categories: list<string>,
         *   tags: list<string>,
         *   variants: list<array{label: string, price_cents: int|null}>
         * }> $definitions
         */
        $definitions = [
            [
                'name' => 'Rosa Eterna Carmesi',
                'sku_root' => 'REC',
                'base_price_cents' => 2500,
                'is_featured' => true,
                'categories' => ['rosas-eternas'],
                'tags' => ['destacado', 'nuevo'],
                'variants' => [
                    ['label' => 'Pequeno',  'price_cents' => 2500],
                    ['label' => 'Mediano',  'price_cents' => 3500],
                    ['label' => 'Grande',   'price_cents' => 5000],
                ],
            ],
            [
                'name' => 'Bouquet Aurora',
                'sku_root' => 'BAU',
                'base_price_cents' => 4500,
                'is_featured' => true,
                'categories' => ['bouquets-de-novia'],
                'tags' => ['destacado'],
                'variants' => [
                    ['label' => 'Clasico',  'price_cents' => 4500],
                    ['label' => 'Premium',  'price_cents' => 7500],
                ],
            ],
            [
                'name' => 'Centro de Mesa Romantico',
                'sku_root' => 'CMR',
                'base_price_cents' => 6000,
                'is_featured' => false,
                'categories' => ['centros-de-mesa'],
                'tags' => ['regalo'],
                'variants' => [
                    ['label' => 'Pequeno',  'price_cents' => 6000],
                    ['label' => 'Grande',   'price_cents' => 9000],
                ],
            ],
            [
                'name' => 'Ramo de Girasoles',
                'sku_root' => 'RGI',
                'base_price_cents' => 3000,
                'is_featured' => false,
                'categories' => ['arreglos-florales'],
                'tags' => [],
                'variants' => [
                    ['label' => '6 tallos',  'price_cents' => 3000],
                    ['label' => '12 tallos', 'price_cents' => 5500],
                ],
            ],
            [
                'name' => 'Cofre de Rosas Preservadas',
                'sku_root' => 'CRP',
                'base_price_cents' => 5500,
                'is_featured' => true,
                'categories' => ['rosas-eternas'],
                'tags' => ['nuevo', 'destacado'],
                'variants' => [
                    ['label' => 'Rojo',   'price_cents' => 5500],
                    ['label' => 'Blanco', 'price_cents' => 5500],
                    ['label' => 'Rosa',   'price_cents' => 5500],
                ],
            ],
            [
                'name' => 'Bouquet Novia Blanco',
                'sku_root' => 'BNB',
                'base_price_cents' => 8000,
                'is_featured' => true,
                'categories' => ['bouquets-de-novia', 'bodas'],
                'tags' => ['destacado'],
                'variants' => [
                    ['label' => 'Compacto', 'price_cents' => 8000],
                    ['label' => 'Cascada',  'price_cents' => 12000],
                ],
            ],
            [
                'name' => 'Arreglo Primavera',
                'sku_root' => 'APR',
                'base_price_cents' => 3500,
                'is_featured' => false,
                'categories' => ['arreglos-florales'],
                'tags' => ['oferta'],
                'variants' => [
                    ['label' => 'Pequeno', 'price_cents' => 3500],
                    ['label' => 'Grande',  'price_cents' => 5800],
                ],
            ],
            [
                'name' => 'Orquidea Morada',
                'sku_root' => 'ORM',
                'base_price_cents' => 4000,
                'is_featured' => false,
                'categories' => ['arreglos-florales'],
                'tags' => ['nuevo'],
                'variants' => [
                    ['label' => 'Planta sola',  'price_cents' => 4000],
                    ['label' => 'Con canasta',  'price_cents' => 5500],
                ],
            ],
            [
                'name' => 'Tulipanes Multicolor',
                'sku_root' => 'TUM',
                'base_price_cents' => 2800,
                'is_featured' => false,
                'categories' => ['arreglos-florales'],
                'tags' => [],
                'variants' => [
                    ['label' => '6 tallos',  'price_cents' => 2800],
                    ['label' => '12 tallos', 'price_cents' => 5000],
                ],
            ],
            [
                'name' => 'Peluche Osito Corazon',
                'sku_root' => 'POC',
                'base_price_cents' => 1500,
                'is_featured' => false,
                'categories' => ['peluches'],
                'tags' => ['regalo'],
                'variants' => [
                    ['label' => 'Pequeno',  'price_cents' => 1500],
                    ['label' => 'Mediano',  'price_cents' => 2500],
                    ['label' => 'Grande',   'price_cents' => 3500],
                ],
            ],
            [
                'name' => 'Globo Metalico Cumpleanos',
                'sku_root' => 'GMC',
                'base_price_cents' => 800,
                'is_featured' => false,
                'categories' => ['globos', 'cumpleanos'],
                'tags' => [],
                'variants' => [
                    ['label' => 'Sin helio',  'price_cents' => 800],
                    ['label' => 'Con helio',  'price_cents' => 1200],
                ],
            ],
            [
                'name' => 'Arreglo Bebe Recien Nacido',
                'sku_root' => 'ABR',
                'base_price_cents' => 4500,
                'is_featured' => true,
                'categories' => ['regalos'],
                'tags' => ['nuevo'],
                'variants' => [
                    ['label' => 'Nino',  'price_cents' => 4500],
                    ['label' => 'Nina',  'price_cents' => 4500],
                ],
            ],
            [
                'name' => 'Kit Spa Lavanda',
                'sku_root' => 'KSL',
                'base_price_cents' => 3200,
                'is_featured' => false,
                'categories' => ['regalos'],
                'tags' => ['oferta', 'regalo'],
                'variants' => [
                    ['label' => 'Basico',   'price_cents' => 3200],
                    ['label' => 'Completo', 'price_cents' => 5500],
                ],
            ],
            [
                'name' => 'Canasta Regalo Deluxe',
                'sku_root' => 'CGD',
                'base_price_cents' => 7000,
                'is_featured' => true,
                'categories' => ['regalos'],
                'tags' => ['destacado', 'regalo'],
                'variants' => [
                    ['label' => 'Mediana', 'price_cents' => 7000],
                    ['label' => 'Grande',  'price_cents' => 9500],
                ],
            ],
            [
                'name' => 'Vela Aromatica Rosas',
                'sku_root' => 'VAR',
                'base_price_cents' => 1800,
                'is_featured' => false,
                'categories' => ['regalos'],
                'tags' => [],
                'variants' => [
                    ['label' => '100g',  'price_cents' => 1800],
                    ['label' => '250g',  'price_cents' => 3200],
                ],
            ],
            [
                'name' => 'Bouquet Tropical Hibisco',
                'sku_root' => 'BTH',
                'base_price_cents' => 3800,
                'is_featured' => false,
                'categories' => ['arreglos-florales'],
                'tags' => ['nuevo'],
                'variants' => [
                    ['label' => 'Pequeno', 'price_cents' => 3800],
                    ['label' => 'Grande',  'price_cents' => 6000],
                ],
            ],
            [
                'name' => 'Arreglo Aniversario Eterno',
                'sku_root' => 'AAE',
                'base_price_cents' => 5500,
                'is_featured' => true,
                'categories' => ['aniversarios', 'rosas-eternas'],
                'tags' => ['destacado'],
                'variants' => [
                    ['label' => 'Rosa',   'price_cents' => 5500],
                    ['label' => 'Rojo',   'price_cents' => 5500],
                    ['label' => 'Blanco', 'price_cents' => 5500],
                ],
            ],
            [
                'name' => 'Decoracion Mesa Boda',
                'sku_root' => 'DMB',
                'base_price_cents' => 9500,
                'is_featured' => true,
                'categories' => ['bodas', 'centros-de-mesa'],
                'tags' => ['destacado'],
                'variants' => [
                    ['label' => 'Clasica', 'price_cents' => 9500],
                    ['label' => 'Rustica', 'price_cents' => 11000],
                ],
            ],
            [
                'name' => 'Porta-retrato Floral',
                'sku_root' => 'PRF',
                'base_price_cents' => 2200,
                'is_featured' => false,
                'categories' => ['regalos'],
                'tags' => ['oferta'],
                'variants' => [
                    ['label' => '4x6',  'price_cents' => 2200],
                    ['label' => '5x7',  'price_cents' => 2800],
                ],
            ],
            [
                'name' => 'Pulsera Floral Plata',
                'sku_root' => 'PFP',
                'base_price_cents' => 3500,
                'is_featured' => false,
                'categories' => ['regalos'],
                'tags' => ['nuevo', 'regalo'],
                'variants' => [
                    ['label' => 'Talla S', 'price_cents' => 3500],
                    ['label' => 'Talla M', 'price_cents' => 3500],
                    ['label' => 'Talla L', 'price_cents' => 3500],
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $this->upsertProduct($tenant, $categories, $tags, $definition);
        }
    }

    // -------------------------------------------------------------------------
    // tatiana products (COP, gift shop)
    // -------------------------------------------------------------------------

    private function seedTatiana(Tenant $tenant): void
    {
        $categories = Category::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('slug');

        $tags = Tag::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('slug');

        /**
         * COP pricing note: base_price_cents stores the full COP integer amount.
         * There are no fractional centavos in everyday COP commerce, so 50000 = COP $50,000.
         *
         * @var list<array{
         *   name: string,
         *   sku_root: string,
         *   base_price_cents: int,
         *   is_featured: bool,
         *   categories: list<string>,
         *   tags: list<string>,
         *   variants: list<array{label: string, price_cents: int|null}>
         * }> $definitions
         */
        $definitions = [
            [
                'name' => 'Marco Personalizado Madera',
                'sku_root' => 'MPM',
                'base_price_cents' => 45000,
                'is_featured' => true,
                'categories' => ['regalos-personalizados'],
                'tags' => ['destacado', 'nuevo'],
                'variants' => [
                    ['label' => '4x6',  'price_cents' => 45000],
                    ['label' => '8x10', 'price_cents' => 65000],
                ],
            ],
            [
                'name' => 'Collar Dorado Corazon',
                'sku_root' => 'CDC',
                'base_price_cents' => 85000,
                'is_featured' => true,
                'categories' => ['joyeria'],
                'tags' => ['destacado'],
                'variants' => [
                    ['label' => 'Cadena 40cm', 'price_cents' => 85000],
                    ['label' => 'Cadena 45cm', 'price_cents' => 90000],
                ],
            ],
            [
                'name' => 'Caja de Chocolates Artesanal',
                'sku_root' => 'CCA',
                'base_price_cents' => 55000,
                'is_featured' => false,
                'categories' => ['regalos-personalizados'],
                'tags' => ['regalo', 'oferta'],
                'variants' => [
                    ['label' => '12 unidades', 'price_cents' => 55000],
                    ['label' => '24 unidades', 'price_cents' => 95000],
                ],
            ],
            [
                'name' => 'Pulsera Plata Iniciales',
                'sku_root' => 'PPI',
                'base_price_cents' => 120000,
                'is_featured' => true,
                'categories' => ['joyeria'],
                'tags' => ['nuevo', 'destacado'],
                'variants' => [
                    ['label' => 'Plata 925',  'price_cents' => 120000],
                    ['label' => 'Bano oro',   'price_cents' => 145000],
                ],
            ],
            [
                'name' => 'Difusor Aromas Hogar',
                'sku_root' => 'DAH',
                'base_price_cents' => 75000,
                'is_featured' => false,
                'categories' => ['decoracion-hogar'],
                'tags' => ['nuevo'],
                'variants' => [
                    ['label' => 'Lavanda',   'price_cents' => 75000],
                    ['label' => 'Citricos',  'price_cents' => 75000],
                    ['label' => 'Vanilla',   'price_cents' => 75000],
                ],
            ],
            [
                'name' => 'Kit Bienestar Corporativo',
                'sku_root' => 'KBC',
                'base_price_cents' => 180000,
                'is_featured' => true,
                'categories' => ['detalles-corporativos'],
                'tags' => ['destacado', 'regalo'],
                'variants' => [
                    ['label' => 'Estandar', 'price_cents' => 180000],
                    ['label' => 'Premium',  'price_cents' => 280000],
                ],
            ],
            [
                'name' => 'Cuadro Decorativo Minimalista',
                'sku_root' => 'CDM',
                'base_price_cents' => 95000,
                'is_featured' => false,
                'categories' => ['decoracion-hogar'],
                'tags' => [],
                'variants' => [
                    ['label' => '20x20',  'price_cents' => 95000],
                    ['label' => '40x40',  'price_cents' => 150000],
                ],
            ],
            [
                'name' => 'Agenda Personalizada Empresarial',
                'sku_root' => 'APE',
                'base_price_cents' => 65000,
                'is_featured' => false,
                'categories' => ['detalles-corporativos', 'regalos-personalizados'],
                'tags' => ['oferta'],
                'variants' => [
                    ['label' => 'A5 Tapa Blanda', 'price_cents' => 65000],
                    ['label' => 'A4 Tapa Dura',   'price_cents' => 85000],
                ],
            ],
            [
                'name' => 'Aretes Perla Natural',
                'sku_root' => 'APN',
                'base_price_cents' => 110000,
                'is_featured' => false,
                'categories' => ['joyeria'],
                'tags' => ['nuevo'],
                'variants' => [
                    ['label' => 'Argolla',   'price_cents' => 110000],
                    ['label' => 'Garfio',    'price_cents' => 110000],
                ],
            ],
            [
                'name' => 'Veladora Soja Premium',
                'sku_root' => 'VSP',
                'base_price_cents' => 48000,
                'is_featured' => false,
                'categories' => ['decoracion-hogar'],
                'tags' => ['oferta'],
                'variants' => [
                    ['label' => 'Rose Gold',  'price_cents' => 48000],
                    ['label' => 'Marmol',     'price_cents' => 52000],
                ],
            ],
            [
                'name' => 'Set Papeleria Premium',
                'sku_root' => 'SPP',
                'base_price_cents' => 90000,
                'is_featured' => true,
                'categories' => ['detalles-corporativos'],
                'tags' => ['destacado'],
                'variants' => [
                    ['label' => 'Clasico',  'price_cents' => 90000],
                    ['label' => 'Ejecutivo', 'price_cents' => 130000],
                ],
            ],
            [
                'name' => 'Florero Ceramica Artesanal',
                'sku_root' => 'FCA',
                'base_price_cents' => 70000,
                'is_featured' => false,
                'categories' => ['decoracion-hogar'],
                'tags' => ['nuevo'],
                'variants' => [
                    ['label' => 'Alto',  'price_cents' => 70000],
                    ['label' => 'Bajo',  'price_cents' => 55000],
                ],
            ],
            [
                'name' => 'Caja Regalo Corporativa',
                'sku_root' => 'CGC',
                'base_price_cents' => 200000,
                'is_featured' => true,
                'categories' => ['detalles-corporativos'],
                'tags' => ['destacado', 'regalo'],
                'variants' => [
                    ['label' => 'Mediana', 'price_cents' => 200000],
                    ['label' => 'Grande',  'price_cents' => 320000],
                ],
            ],
            [
                'name' => 'Neceser Personalizado',
                'sku_root' => 'NPS',
                'base_price_cents' => 58000,
                'is_featured' => false,
                'categories' => ['regalos-personalizados'],
                'tags' => ['regalo'],
                'variants' => [
                    ['label' => 'Negro',  'price_cents' => 58000],
                    ['label' => 'Rosa',   'price_cents' => 58000],
                    ['label' => 'Beige',  'price_cents' => 58000],
                ],
            ],
            [
                'name' => 'Set Mugs Personalizados',
                'sku_root' => 'SMP',
                'base_price_cents' => 40000,
                'is_featured' => false,
                'categories' => ['regalos-personalizados'],
                'tags' => ['oferta', 'nuevo'],
                'variants' => [
                    ['label' => 'x1 unidad', 'price_cents' => 40000],
                    ['label' => 'x2 unidades', 'price_cents' => 72000],
                ],
            ],
            [
                'name' => 'Anillo Acero Inoxidable',
                'sku_root' => 'AAI',
                'base_price_cents' => 35000,
                'is_featured' => false,
                'categories' => ['joyeria'],
                'tags' => ['oferta'],
                'variants' => [
                    ['label' => 'Talla 6',  'price_cents' => 35000],
                    ['label' => 'Talla 7',  'price_cents' => 35000],
                    ['label' => 'Talla 8',  'price_cents' => 35000],
                ],
            ],
            [
                'name' => 'Tapete Redondo Bohemio',
                'sku_root' => 'TRB',
                'base_price_cents' => 125000,
                'is_featured' => false,
                'categories' => ['decoracion-hogar'],
                'tags' => [],
                'variants' => [
                    ['label' => '60cm',  'price_cents' => 125000],
                    ['label' => '90cm',  'price_cents' => 190000],
                ],
            ],
            [
                'name' => 'Taza con Nombre Grabado',
                'sku_root' => 'TNG',
                'base_price_cents' => 32000,
                'is_featured' => false,
                'categories' => ['regalos-personalizados'],
                'tags' => ['nuevo', 'regalo'],
                'variants' => [
                    ['label' => 'Blanca',   'price_cents' => 32000],
                    ['label' => 'Negra',    'price_cents' => 32000],
                ],
            ],
            [
                'name' => 'Lampara Mesa Vintage',
                'sku_root' => 'LMV',
                'base_price_cents' => 160000,
                'is_featured' => true,
                'categories' => ['decoracion-hogar'],
                'tags' => ['destacado'],
                'variants' => [
                    ['label' => 'Bronce', 'price_cents' => 160000],
                    ['label' => 'Negro',  'price_cents' => 155000],
                ],
            ],
            [
                'name' => 'Pen Drive Personalizado',
                'sku_root' => 'PVP',
                'base_price_cents' => 28000,
                'is_featured' => false,
                'categories' => ['detalles-corporativos'],
                'tags' => ['oferta'],
                'variants' => [
                    ['label' => '16 GB', 'price_cents' => 28000],
                    ['label' => '32 GB', 'price_cents' => 38000],
                ],
            ],
        ];

        foreach ($definitions as $definition) {
            $this->upsertProduct($tenant, $categories, $tags, $definition);
        }
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Insert or update a product and its variants.
     *
     * Product is keyed on (tenant_id, slug derived from name).
     * Variants are keyed on (product_id, sku derived from sku_root + label).
     *
     * @param  Collection<string, Category>  $categories
     * @param  Collection<string, Tag>  $tags
     * @param  array{
     *   name: string,
     *   sku_root: string,
     *   base_price_cents: int,
     *   is_featured: bool,
     *   categories: list<string>,
     *   tags: list<string>,
     *   variants: list<array{label: string, price_cents: int|null}>
     * } $definition
     */
    private function upsertProduct(
        Tenant $tenant,
        Collection $categories,
        Collection $tags,
        array $definition,
    ): void {
        $slug = Str::slug($definition['name']);

        $product = Product::withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => $slug],
            [
                'name' => $definition['name'],
                'sku_root' => $definition['sku_root'],
                'description' => null,
                'base_price_cents' => $definition['base_price_cents'],
                'gallery' => self::GALLERY,
                'is_active' => true,
                'is_featured' => $definition['is_featured'],
            ],
        );

        // Attach categories (M2M) — syncWithoutDetaching so re-seeding is safe.
        $categoryIds = collect($definition['categories'])
            ->map(fn (string $catSlug) => $categories->get($catSlug)?->id)
            ->filter()
            ->values()
            ->all();

        if ($categoryIds !== []) {
            $product->categories()->syncWithoutDetaching($categoryIds);
        }

        // Attach tags (M2M) — syncWithoutDetaching.
        $tagIds = collect($definition['tags'])
            ->map(fn (string $tagSlug) => $tags->get($tagSlug)?->id)
            ->filter()
            ->values()
            ->all();

        if ($tagIds !== []) {
            $product->tags()->syncWithoutDetaching($tagIds);
        }

        // Create variants if they don't exist yet.
        foreach ($definition['variants'] as $position => $variantDef) {
            $sku = $definition['sku_root'].'-'.strtoupper(Str::slug($variantDef['label']));

            ProductVariant::withoutGlobalScopes()->firstOrCreate(
                ['product_id' => $product->id, 'sku' => $sku],
                [
                    'price_cents' => $variantDef['price_cents'],
                    'options' => ['Presentacion' => $variantDef['label']],
                    'position' => $position,
                ],
            );
        }
    }
}
