<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\Reservations\ReservationPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single payment instalment recorded against a Reservation.
 *
 * After each insert the service layer increments deposit_paid_cents on the
 * parent reservation. Do not mutate deposit_paid_cents directly — always
 * go through ReservationService::recordPayment().
 *
 * amount_cents is stored in centavos (int). Never expose raw _cents columns
 * in API responses — format in API Resources.
 */
final class ReservationPayment extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReservationPaymentFactory> */
    use HasFactory;

    protected static function newFactory(): ReservationPaymentFactory
    {
        return ReservationPaymentFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'reservation_id',
        'amount_cents',
        'payment_method',
        'reference',
        'recorded_by',
        'paid_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * The staff member who recorded this payment.
     *
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
