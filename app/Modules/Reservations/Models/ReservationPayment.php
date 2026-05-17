<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationPayment extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'reservation_id',
        'amount',
        'payment_method',
        'reference',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
