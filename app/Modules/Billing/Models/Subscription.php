<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Domain\States\SubscriptionState;
use App\Modules\Billing\Observers\SubscriptionObserver;
use App\Modules\Plans\Models\Plan;
use App\Modules\Tenancy\Models\Tenant;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory;

    use SoftDeletes;

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
        'next_billing_at',
        'last_paid_at',
        'billing_period',
        'currency',
        'amount_cents',
        'gateway_subscription_id',
        'affiliation_url',
        'affiliation_qr_url',
        'gateway_customer_id',
        'card_token',
        'card_last4',
        'card_brand',
        'card_exp_month',
        'card_exp_year',
        'past_due_since',
        'trial_reminder_sent_at',
    ];

    /**
     * card_token holds the gateway token (never the PAN) and is encrypted at rest and
     * hidden from every array/JSON serialization so it cannot leak through an API Resource.
     *
     * @var list<string>
     */
    protected $hidden = [
        'card_token',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'trial_ends_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'canceled_at' => 'datetime',
        'next_billing_at' => 'datetime',
        'last_paid_at' => 'datetime',
        'past_due_since' => 'datetime',
        'trial_reminder_sent_at' => 'datetime',
        'amount_cents' => 'integer',
        'card_exp_month' => 'integer',
        'card_exp_year' => 'integer',
        'card_token' => 'encrypted',
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
     * The State object for the current status column. All status transitions must go
     * through $subscription->state()->applyTransition(...) so they are validated and
     * audited; the raw `status` column remains the source of truth it reads from.
     */
    public function state(): SubscriptionState
    {
        return SubscriptionState::for($this);
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
