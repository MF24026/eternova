<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Modules\Customers\Models\Customer;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'description',
        'occasion',
        'delivery_date',
        'total_amount',
        'deposit_amount',
        'deposit_paid',
        'status',
        'special_instructions',
        'admin_notes',
    ];

    protected $casts = [
        'delivery_date' => 'date',
        'total_amount' => 'integer',
        'deposit_amount' => 'integer',
        'deposit_paid' => 'integer',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ReservationPayment::class);
    }

    public function remainingBalance(): int
    {
        return $this->total_amount - $this->deposit_paid;
    }
}
