<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Models\User;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\Orders\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only record of every status transition on an Order.
 *
 * Rows are immutable once written: no updated_at, $timestamps = false
 * with CREATED_AT set so Eloquent stamps created_at on insert automatically.
 *
 * from_status is null for the initial creation entry — the order was
 * born directly into to_status (e.g. a POS sale starts as 'preparing').
 *
 * user_id is null when the transition was made by the system or a customer
 * (not by an identified staff member).
 */
final class OrderStatusHistory extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    // Eloquent pluralises class names: "order_status_history" → "order_status_histories".
    // Explicitly name the table to match the migration.
    protected $table = 'order_status_history';

    // Eloquent will stamp created_at on insert; updated_at is not tracked.
    public const UPDATED_AT = null;

    protected static function newFactory(): OrderStatusHistoryFactory
    {
        return OrderStatusHistoryFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'order_id',
        'from_status',
        'to_status',
        'user_id',
        'note',
    ];

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
