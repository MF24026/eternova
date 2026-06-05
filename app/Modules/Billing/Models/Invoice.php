<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'number',
        'status',
        'subtotal_cents',
        'tax_cents',
        'total_cents',
        'currency',
        'due_at',
        'paid_at',
        'wompi_transaction_id',
        'pdf_url',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'subtotal_cents' => 'integer',
        'tax_cents' => 'integer',
        'total_cents' => 'integer',
        'due_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Whether the invoice is past its due date and still not paid.
     */
    public function isOverdue(): bool
    {
        return ! $this->isPaid()
            && $this->due_at !== null
            && $this->due_at->isPast();
    }

    /**
     * Verify that total_cents equals subtotal_cents + tax_cents.
     * Used in service-layer assertions before persisting.
     */
    public function hasTotalsConsistency(): bool
    {
        return $this->total_cents === ($this->subtotal_cents + $this->tax_cents);
    }
}
