<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Product extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'sku_root',
        'base_price_cents',
        'cost_price_cents',
        'default_image_url',
        'gallery',
        'is_active',
        'is_featured',
        'tax_rate',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'base_price_cents' => 'integer',
        'cost_price_cents' => 'integer',
        'gallery' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'tax_rate' => 'decimal:4',
    ];

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /**
     * Options are ordered by position so the editor renders them consistently.
     *
     * @return HasMany<ProductOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ProductOption::class)->orderBy('position');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product')
            ->using(CategoryProduct::class)
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag')
            ->withTimestamps();
    }

    /**
     * Returns the variant's price if it has one; otherwise falls back to this
     * product's base price. This is the single authoritative price lookup — never
     * read price_cents directly from a variant without going through this method.
     */
    public function effectivePriceCents(?ProductVariant $variant = null): int
    {
        if ($variant !== null && $variant->price_cents !== null) {
            return $variant->price_cents;
        }

        return $this->base_price_cents;
    }
}
