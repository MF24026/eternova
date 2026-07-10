<?php

declare(strict_types=1);

/*
 * Single source of truth for business verticals (giros). One entry fully describes a
 * giro: `label`, `icon` (Lucide name for the onboarding picker), `modules` (the
 * optional/gateable modules ON by default — core modules are never listed), and
 * `starter_template` (the StarterCatalogService template seeded at signup, or null for
 * giros without a demo catalog yet). Adding a giro is one entry here plus a case on
 * App\Modules\Tenancy\Enums\BusinessType.
 */
return [
    'catalog' => [
        'floreria_regalos' => ['label' => 'Florería / Regalos', 'icon' => 'flower', 'modules' => ['reservations', 'quotations'], 'starter_template' => 'floreria'],
        'ropa_boutique' => ['label' => 'Ropa / Boutique', 'icon' => 'shirt', 'modules' => [], 'starter_template' => null],
        'accesorios' => ['label' => 'Accesorios / Maquillaje', 'icon' => 'gem', 'modules' => [], 'starter_template' => 'accesorios'],
        'peluches' => ['label' => 'Peluches / Juguetería', 'icon' => 'gift', 'modules' => [], 'starter_template' => 'peluches'],
        'minimarket' => ['label' => 'Minimarket / Abarrotes', 'icon' => 'shopping-basket', 'modules' => [], 'starter_template' => null],
        'otro' => ['label' => 'Otro', 'icon' => 'store', 'modules' => ['reservations', 'quotations'], 'starter_template' => null],
    ],
];
