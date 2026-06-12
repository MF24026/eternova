<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Reservations\ReservationStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every status transition on a Reservation.
 *
 * Rows are immutable once written: no updated_at, $timestamps = false
 * with CREATED_AT set so Eloquent stamps created_at on insert automatically.
 *
 * from_status is null for the initial creation entry — the reservation was
 * born directly into to_status (e.g. a new capture starts as 'inquiry').
 *
 * user_id is null when the transition was made by the system or a customer
 * (not by an identified staff member).
 */
final class ReservationStatusHistory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReservationStatusHistoryFactory> */
    use HasFactory;

    // Eloquent pluralises class names: "reservation_status_history" →
    // "reservation_status_histories". Explicitly name the table to match the migration.
    protected $table = 'reservation_status_history';

    // Eloquent will stamp created_at on insert; updated_at is not tracked.
    public const UPDATED_AT = null;

    protected static function newFactory(): ReservationStatusHistoryFactory
    {
        return ReservationStatusHistoryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'reservation_id',
        'from_status',
        'to_status',
        'user_id',
        'note',
    ];

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
