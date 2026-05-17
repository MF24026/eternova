<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'status',
        'brand_config',
        'locale_config',
        'trial_ends_at',
    ];

    protected $casts = [
        'brand_config'   => 'array',
        'locale_config'  => 'array',
        'trial_ends_at'  => 'datetime',
        'status'         => 'string',
    ];

    /**
     * Retrieve a tenant by its subdomain slug.
     */
    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isOnTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(\App\Modules\Billing\Models\Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(\App\Modules\Billing\Models\Invoice::class);
    }
}
