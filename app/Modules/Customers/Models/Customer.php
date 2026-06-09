<?php

declare(strict_types=1);

namespace App\Modules\Customers\Models;

use App\Modules\Orders\Models\Order;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Customers\CustomerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    /**
     * Models under app/Modules/ do not match Laravel's default factory-name
     * convention (Database\Factories\{Model}Factory), so the factory is resolved
     * explicitly. Without this, Factory::factoryForModel() fails to find it.
     */
    protected static function newFactory(): CustomerFactory
    {
        return CustomerFactory::new();
    }

    protected $fillable = [
        'tenant_id',
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
