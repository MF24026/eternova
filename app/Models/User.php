<?php

declare(strict_types=1);

namespace App\Models;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'avatar_url',
        'is_super_admin',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * The tenants this user belongs to, with their per-tenant role and join date.
     *
     * @return BelongsToMany<Tenant>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_users')
            ->using(TenantUser::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps()
            ->orderByPivot('joined_at');
    }

    /**
     * Return the role this user has in the current tenant context.
     *
     * Returns null when there is no active tenant or when the user has no membership
     * in the current tenant. Result is cached for the duration of this request using
     * a static property keyed by [user_id]:[tenant_id].
     */
    public function currentRole(): ?string
    {
        $tenant = current_tenant();

        if ($tenant === null) {
            return null;
        }

        // Per-request static cache: avoids repeated DB queries for the same user+tenant
        // across multiple controller/service/policy calls in a single HTTP request.
        static $cache = [];

        $key = $this->id.':'.$tenant->id;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $pivot = $this->tenants()
            ->where('tenants.id', $tenant->id)
            ->first();

        $cache[$key] = $pivot?->pivot?->role;

        return $cache[$key];
    }

    /**
     * Whether this user has any membership in the given tenant.
     */
    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->tenants()
            ->where('tenants.id', $tenant->id)
            ->exists();
    }
}
