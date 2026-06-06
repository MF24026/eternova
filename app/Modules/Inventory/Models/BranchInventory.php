<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Inventory\BranchInventoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks the current stock level of a single ProductVariant at a specific Branch.
 *
 * `available` is a MySQL GENERATED column (quantity - reserved) — never include it in
 * inserts or updates. It is cast to integer for convenience but cannot be written.
 */
final class BranchInventory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<BranchInventoryFactory> */
    use HasFactory;

    public $table = 'branch_inventory';

    protected static function newFactory(): BranchInventoryFactory
    {
        return BranchInventoryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'product_variant_id',
        'quantity',
        'reserved',
    ];

    /**
     * `available` is deliberately absent from $fillable.
     * Guard it explicitly so that mass-assignment attempts fail visibly.
     *
     * @var list<string>
     */
    protected $guarded = ['available'];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'reserved' => 'integer',
        'available' => 'integer',
    ];

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
