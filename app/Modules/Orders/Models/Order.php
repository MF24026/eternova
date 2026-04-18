<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_id',
        'status',
        'subtotal',
        'tax',
        'delivery_fee',
        'total',
        'source',
        'payment_method',
        'payment_status',
        'notes',
        'shipping_address',
        'tracking_id',
        'dispatched_at',
        'delivered_at',
    ];

    protected $casts = [
        'subtotal' => 'integer',
        'tax' => 'integer',
        'delivery_fee' => 'integer',
        'total' => 'integer',
        'dispatched_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
