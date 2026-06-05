<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Models\Subscription;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use HasUlids;
    use SoftDeletes;

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    /**
     * Identity + status fields are fillable for onboarding and super-admin updates.
     * Brand and locale explicit columns are fillable for tenant settings management.
     * JSON extras (brand_extra, locale_extra) are fillable for extensible metadata.
     *
     * @var list<string>
     */
    protected $fillable = [
        // Identity
        'name',
        'slug',
        'email',
        'status',

        // Brand — explicit columns
        'business_name',
        'logo_url',
        'primary_color',
        'secondary_color',
        'favicon_url',

        // Brand — extensible JSON (tagline, social_links, dark_logo_url, etc.)
        'brand_extra',

        // Locale — explicit columns
        'currency',
        'country_code',
        'language',
        'timezone',

        // Locale — extensible JSON (date_format, phone_format, tax_rates_default, etc.)
        'locale_extra',

        'trial_ends_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'brand_extra' => 'array',
        'locale_extra' => 'array',
        'trial_ends_at' => 'datetime',
        'status' => 'string',
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

    /**
     * Users who are members of this tenant, with their per-tenant role.
     *
     * @return BelongsToMany<User>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->using(TenantUser::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps()
            ->orderByPivot('joined_at');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
