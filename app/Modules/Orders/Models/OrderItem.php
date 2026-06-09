<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Catalog\Models\ProductVariant;
use Database\Factories\Orders\OrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single line item within an Order.
 *
 * No BelongsToTenant: tenant isolation is inherited through the parent Order.
 * Always query order items through their order, never directly.
 *
 * product_snapshot is the receipt's source of truth. It captures name,
 * variant_options, and sku at the moment of sale — immutable from that point on.
 * Even if the product is renamed or soft-deleted, the item retains what was sold.
 */
final class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    protected static function newFactory(): OrderItemFactory
    {
        return OrderItemFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_variant_id',
        'quantity',
        'unit_price_cents',
        'total_cents',
        'product_snapshot',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'quantity' => 'integer',
        'unit_price_cents' => 'integer',
        'total_cents' => 'integer',
        'product_snapshot' => 'array',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<ProductVariant, $this>
     */
    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
