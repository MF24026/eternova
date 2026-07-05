<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\Orders\OrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A sale or reservation that belongs to a specific branch of a tenant.
 *
 * Monetary values are always stored in centavos (int). Never expose raw
 * _cents columns in API responses — format in API Resources.
 *
 * order_number is generated per-tenant (CC-{year}-{seq}) using a locked
 * sequence row in order_sequences. See OrderService::nextOrderNumber().
 */
final class Order extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function newFactory(): OrderFactory
    {
        return OrderFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'customer_id',
        'order_number',
        'tracking_token',
        'status',
        'source',
        'subtotal_cents',
        'tax_cents',
        'tax_rate_bps',
        'discount_cents',
        'total_cents',
        'payment_method',
        'payment_status',
        'notes',
        'user_id',
        'assigned_to',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'tax_rate_bps' => 'integer',
        'discount_cents' => 'integer',
        'total_cents' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The staff member currently assigned to prepare or dispatch this order.
     *
     * Null when the order has not been assigned to anyone.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Full audit trail of every status transition, oldest-first.
     *
     * Call ->latest('created_at') at the query level if you need reverse order.
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->oldest('created_at');
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    /**
     * Mark the order as fully paid and persist.
     *
     * Does not open a transaction — caller is responsible for wrapping in one
     * if this must be atomic with other writes.
     */
    public function markPaid(): void
    {
        $this->update(['payment_status' => 'paid']);
    }
}
