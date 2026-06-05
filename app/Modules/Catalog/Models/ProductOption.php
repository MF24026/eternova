<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\ProductOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable dimension of a product: Color, Tamano, Material, etc.
 *
 * No BelongsToTenant: scoped indirectly through the parent Product.
 * UNIQUE (product_id, name) enforced at DB level — same option name cannot
 * appear twice on the same product.
 */
final class ProductOption extends Model
{
    /** @use HasFactory<ProductOptionFactory> */
    use HasFactory;

    protected static function newFactory(): ProductOptionFactory
    {
        return ProductOptionFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'name',
        'position',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Values are ordered by position to render the swatches/dropdown consistently.
     *
     * @return HasMany<ProductOptionValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ProductOptionValue::class, 'option_id')->orderBy('position');
    }
}
