<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Observers\SubscriptionObserver;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'canceled_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'canceled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::observe(SubscriptionObserver::class);
    }

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Whether the subscription grants access to the platform.
     * Both active and trialing subscriptions are considered "in access".
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTrialing(): bool
    {
        return $this->status === 'trialing';
    }

    public function isPastDue(): bool
    {
        return $this->status === 'past_due';
    }

    public function isCanceled(): bool
    {
        return $this->status === 'canceled';
    }

    /**
     * Whether the subscription was canceled but is still within its paid period.
     * Tenant retains access until current_period_end.
     */
    public function isOnGracePeriod(): bool
    {
        return $this->isCanceled()
            && $this->current_period_end !== null
            && $this->current_period_end->isFuture();
    }

    /**
     * Days remaining until the trial ends. Returns 0 if trial has already ended.
     */
    public function daysUntilTrialEnds(): int
    {
        if ($this->trial_ends_at === null) {
            return 0;
        }

        $diff = (int) now()->diffInDays($this->trial_ends_at, absolute: false);

        return max(0, $diff);
    }
}
