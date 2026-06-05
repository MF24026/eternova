<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Modules\Catalog\Models\ProductOption;
use App\Modules\Catalog\Models\ProductOptionValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductOptionValue>
 *
 * IMPORTANT: always call forOption($option) — a value cannot exist without an option.
 */
class ProductOptionValueFactory extends Factory
{
    protected $model = ProductOptionValue::class;

    /**
     * @var list<string>
     */
    private const VALUES = [
        'Rojo',
        'Verde',
        'Azul',
        'Blanco',
        'Rosado',
        'Morado',
        'Grande',
        'Mediano',
        'Pequeno',
        'Extra Grande',
        'Natural',
        'Sintetico',
        'Clasico',
        'Moderno',
        'Lavanda',
        'Rosa',
        'Vainilla',
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // option_id must be set via forOption() state
            'option_id' => null,
            'value' => $this->faker->unique()->randomElement(self::VALUES),
            'position' => $this->faker->numberBetween(0, 10),
        ];
    }

    /**
     * Bind this value to a specific product option.
     */
    public function forOption(ProductOption $option): static
    {
        return $this->state(fn (array $attributes) => [
            'option_id' => $option->id,
        ]);
    }
}
