<?php

declare(strict_types=1);

namespace App\Modules\Plans\Models;

use App\Modules\Billing\Models\Subscription;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'price_monthly_cents',
        'price_yearly_cents',
        'currency',
        'features',
        'limits',
        'is_active',
        'sort_order',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'price_monthly_cents' => 'integer',
        'price_yearly_cents' => 'integer',
        'features' => 'array',
        'limits' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Whether this plan is free (monthly price = 0).
     */
    public function isFree(): bool
    {
        return $this->price_monthly_cents === 0;
    }

    /**
     * Retrieve the value of a specific limit key.
     *
     * Returns null when the limit is explicitly null in JSON — null means unlimited.
     * Plan-gating code must treat null as "no limit enforced".
     */
    public function getLimit(string $key): ?int
    {
        $limits = $this->limits ?? [];

        if (! array_key_exists($key, $limits)) {
            return null;
        }

        return $limits[$key] === null ? null : (int) $limits[$key];
    }

    /**
     * Whether this plan grants unlimited access to a given limit key.
     */
    public function isUnlimited(string $key): bool
    {
        $limits = $this->limits ?? [];

        return array_key_exists($key, $limits) && $limits[$key] === null;
    }

    /**
     * Yearly savings compared to 12 months of monthly billing, in cents.
     */
    public function yearlySavingsCents(): int
    {
        return ($this->price_monthly_cents * 12) - $this->price_yearly_cents;
    }
}
