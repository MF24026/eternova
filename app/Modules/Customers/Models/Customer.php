<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'whatsapp',
        'address',
        'notes',
        'total_purchases',
        'last_purchase_at',
    ];

    protected $casts = [
        'total_purchases' => 'integer',
        'last_purchase_at' => 'datetime',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
