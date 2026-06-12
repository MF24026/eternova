<?php

declare(strict_types=1);

namespace App\Modules\Reservations\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\Reservations\ReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer reservation for a future event (wedding, corporate, birthday, etc.).
 *
 * Monetary values are always stored in centavos (int). Never expose raw _cents
 * columns in API responses — format in API Resources.
 *
 * reservation_number is generated per-tenant (RSV-{year}-{seq}) using a locked
 * sequence row in reservation_sequences. See ReservationService::nextReservationNumber().
 *
 * Lifecycle: inquiry → confirmed → in_progress → ready → delivered (terminal).
 * cancelled is a terminal state reachable from any non-terminal state.
 * On delivery, convertToOrder() creates an Order and sets converted_order_id.
 */
final class Reservation extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ReservationFactory> */
    use HasFactory;
    use SoftDeletes;

    protected static function newFactory(): ReservationFactory
    {
        return ReservationFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'branch_id',
        'customer_id',
        'reservation_number',
        'description',
        'occasion',
        'event_date',
        'total_cents',
        'deposit_required_cents',
        'deposit_paid_cents',
        'status',
        'special_instructions',
        'admin_notes',
        'converted_order_id',
        'assigned_to',
        'created_by',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'event_date' => 'date',
        'total_cents' => 'integer',
        'deposit_required_cents' => 'integer',
        'deposit_paid_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * All partial payments recorded against this reservation, oldest-first.
     *
     * @return HasMany<ReservationPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ReservationPayment::class)->oldest('paid_at');
    }

    /**
     * Full audit trail of every status transition, oldest-first.
     *
     * Call ->latest('created_at') at the query level if you need reverse order.
     *
     * @return HasMany<ReservationStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class)->oldest('created_at');
    }

    /**
     * The staff member responsible for fulfilling this reservation.
     *
     * Null when the reservation has not been assigned to anyone.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * The staff member who originally captured this reservation.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Order created when this reservation was delivered.
     *
     * Null until ReservationService::convertToOrder() has been called.
     *
     * @return BelongsTo<Order, $this>
     */
    public function convertedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'converted_order_id');
    }

    /**
     * How much of the REQUIRED deposit is still unpaid.
     *
     * Returns a non-negative integer (centavos). Zero means the deposit
     * obligation is fully covered; a positive value means staff should
     * collect more before confirming (or an admin override is needed).
     *
     * Distinct from remainingBalanceCents(): this measures against the
     * deposit_required threshold, not the full reservation total.
     */
    public function depositOutstandingCents(): int
    {
        return max(0, $this->deposit_required_cents - $this->deposit_paid_cents);
    }

    /**
     * How much the customer still owes after their partial payments.
     *
     * Returns a non-negative integer (centavos). A negative result would imply
     * overpayment, which the service layer guards against.
     */
    public function remainingBalanceCents(): int
    {
        return $this->total_cents - $this->deposit_paid_cents;
    }
}
