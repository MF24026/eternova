<?php

declare(strict_types=1);

namespace App\Modules\POS\Models;

use App\Models\User;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\POS\CashRegisterSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cashier's cash-register shift at a branch: opened with a starting cash amount,
 * closed by counting the drawer. `expected` (opening + cash sales) vs the counted
 * `closing` gives the `difference` (arqueo: over/short). Orders rung up during the
 * shift link back via orders.cash_register_session_id.
 *
 * Money is always in cents.
 */
final class CashRegisterSession extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<CashRegisterSessionFactory> */
    use HasFactory;

    protected static function newFactory(): CashRegisterSessionFactory
    {
        return CashRegisterSessionFactory::new();
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'user_id',
        'session_number',
        'opening_amount_cents',
        'closing_amount_cents',
        'expected_amount_cents',
        'difference_cents',
        'status',
        'opened_at',
        'closed_at',
        'opening_notes',
        'closing_notes',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'session_number' => 'integer',
        'opening_amount_cents' => 'integer',
        'closing_amount_cents' => 'integer',
        'expected_amount_cents' => 'integer',
        'difference_cents' => 'integer',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'cash_register_session_id');
    }

    /**
     * @return HasMany<CashMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class, 'cash_register_session_id');
    }

    public function getCashInCentsAttribute(): int
    {
        return (int) $this->movements()->where('type', 'in')->sum('amount_cents');
    }

    public function getCashOutCentsAttribute(): int
    {
        return (int) $this->movements()->where('type', 'out')->sum('amount_cents');
    }

    /**
     * The arqueo ladder result: what should physically be in the drawer.
     * opening + cash sales + manual cash-in − manual cash-out.
     */
    public function getExpectedCashCentsAttribute(): int
    {
        return $this->opening_amount_cents + $this->cash_sales_cents + $this->cash_in_cents - $this->cash_out_cents;
    }

    /** Sum of linked orders' totals for a given payment method. */
    private function salesCentsFor(string $paymentMethod): int
    {
        return (int) $this->orders()->where('payment_method', $paymentMethod)->sum('total_cents');
    }

    public function getCashSalesCentsAttribute(): int
    {
        return $this->salesCentsFor('cash');
    }

    public function getCardSalesCentsAttribute(): int
    {
        return $this->salesCentsFor('card');
    }

    public function getTransferSalesCentsAttribute(): int
    {
        return $this->salesCentsFor('transfer');
    }

    public function getTotalSalesCentsAttribute(): int
    {
        return (int) $this->orders()->sum('total_cents');
    }

    public function getOrderCountAttribute(): int
    {
        return $this->orders()->count();
    }
}
