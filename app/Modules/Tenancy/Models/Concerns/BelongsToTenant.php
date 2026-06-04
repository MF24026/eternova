<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Models\Concerns;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Apply this trait to every Eloquent model that stores tenant-scoped business data.
 *
 * What the trait does:
 *  1. Registers TenantScope as a global scope so every query is automatically filtered
 *     by the current tenant. When no tenant is resolved (CLI, super-admin, queued jobs),
 *     TenantScope is a no-op and queries return full-table results.
 *  2. On the `creating` event, auto-sets `tenant_id` from the current tenant when
 *     the attribute is not already provided explicitly.
 *  3. Throws a LogicException when a create is attempted outside any tenant context
 *     AND no `tenant_id` was provided — prevents silent cross-tenant pollution.
 *
 * Do NOT apply to User model — users are global (many-to-many to tenants).
 * Do NOT apply to TenantDomain — super-admin needs unrestricted access.
 */
trait BelongsToTenant
{
    /**
     * Boot the trait: apply global scope and auto-fill tenant_id on create.
     */
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(static function (self $model): void {
            if (! empty($model->tenant_id)) {
                // Explicit tenant_id provided — respect it and move on.
                return;
            }

            /** @var Tenant|null $currentTenant */
            $currentTenant = app()->bound('currentTenant') ? app('currentTenant') : null;

            if ($currentTenant instanceof Tenant) {
                $model->tenant_id = $currentTenant->id;

                return;
            }

            throw new LogicException(
                static::class.'::create() requires a resolved tenant in the container. '
                .'Either resolve a tenant via EnsureTenant middleware, call '
                ."app()->instance('currentTenant', \$tenant) in tests, "
                .'or pass tenant_id explicitly.'
            );
        });
    }

    /**
     * Query scope that explicitly filters by a given tenant ID, bypassing the global
     * scope. Use this when you need to read another tenant's data in super-admin or
     * background-job context.
     *
     * Example:
     *   Branch::forTenant($tenantB->id)->get()
     *
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->withoutGlobalScope(TenantScope::class)->where(
            $this->getTable().'.tenant_id',
            $tenantId
        );
    }

    /**
     * Relationship back to the owning tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
