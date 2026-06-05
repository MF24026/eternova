<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot model for the tenant_users table.
 *
 * This model is used by the User::tenants() and Tenant::users() BelongsToMany
 * relationships. Extending Pivot (not Model) gives us pivot-specific behaviour:
 * no global scopes, correct timestamps, and proper relationship setup.
 */
final class TenantUser extends Pivot
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'joined_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Tenant, TenantUser>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<User, TenantUser>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
