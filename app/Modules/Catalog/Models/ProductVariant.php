<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Inventory\Models\BranchInventory;
use Database\Factories\Catalog\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A concrete purchasable unit of a product (e.g. "Rosa Roja, Talla Grande").
 *
 * No BelongsToTenant: variants are always queried via their parent Product,
 * which already carries the tenant scope. Direct queries on ProductVariant
 * without a Product join are only safe in super-admin / CLI contexts.
 */
final class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function newFactory(): ProductVariantFactory
    {
        return ProductVariantFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'sku',
        'barcode',
        'price_cents',
        'cost_price_cents',
        'weight_grams',
        'min_stock_alert',
        'options',
        'image_url',
        'position',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'price_cents' => 'integer',
        'cost_price_cents' => 'integer',
        'weight_grams' => 'integer',
        'min_stock_alert' => 'integer',
        'options' => 'array',
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Per-branch stock rows for this variant. Tenant-scoped via BranchInventory's
     * BelongsToTenant. Used by the admin variants overlay to show availability.
     *
     * @return HasMany<BranchInventory, $this>
     */
    public function branchInventory(): HasMany
    {
        return $this->hasMany(BranchInventory::class, 'product_variant_id');
    }
}
