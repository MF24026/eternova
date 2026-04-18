<?php

declare(strict_types=1);

namespace App\Modules\Products\Models;

use App\Modules\Inventory\Models\InventoryMovement;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'sku',
        'barcode',
        'price',
        'cost_price',
        'category_id',
        'image',
        'gallery',
        'is_active',
        'is_featured',
        'current_stock',
        'min_stock_alert',
        'tax_rate',
    ];

    protected $casts = [
        'price' => 'integer',
        'cost_price' => 'integer',
        'gallery' => 'array',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'current_stock' => 'integer',
        'min_stock_alert' => 'integer',
        'tax_rate' => 'decimal:2',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function isLowStock(): bool
    {
        return $this->current_stock <= $this->min_stock_alert;
    }
}
