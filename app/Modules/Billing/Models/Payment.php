<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'amount_cents',
        'currency',
        'method',
        'status',
        'gateway',
        'gateway_reference',
        'paid_at',
        'raw_response',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
        // raw_response is the full provider JSON payload.
        // Cast to array for structured access; NEVER expose this in API resources.
        'raw_response' => 'array',
    ];

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function wasSucceeded(): bool
    {
        return $this->status === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
