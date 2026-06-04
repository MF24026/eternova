<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use App\Modules\Billing\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'price_cents',
        'billing_period',
        'features',
        'limits',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'billing_period' => 'string',
    ];

    /**
     * Price formatted in major currency units (dollars/CRC/etc.) for display.
     * Actual storage and computation always uses price_cents.
     */
    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price_cents / 100, 2);
    }

    public function isFree(): bool
    {
        return $this->price_cents === 0;
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Retrieve the value of a specific limit key.
     *
     * @return int|null null means unlimited
     */
    public function getLimit(string $key): ?int
    {
        return isset($this->limits[$key]) ? (int) $this->limits[$key] : null;
    }
}
