<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'invoice_number',
        'amount_cents',
        'status',
        'paid_at',
        'wompi_payment_id',
        'due_date',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
        'due_date' => 'date',
        'status' => 'string',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Amount formatted in major currency units for display.
     * Internal computation always uses amount_cents.
     */
    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount_cents / 100, 2);
    }
}
