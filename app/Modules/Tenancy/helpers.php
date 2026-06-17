<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

if (! function_exists('current_tenant')) {
    /**
     * Return the currently resolved tenant from the service container, or null when
     * there is no active tenant context (CLI, super-admin, queued jobs).
     *
     * The container key 'currentTenant' is registered by TenancyServiceProvider with a
     * null default, so app()->bound() always returns true — we resolve and type-check instead.
     */
    function current_tenant(): ?Tenant
    {
        /** @var Tenant|null $tenant */
        $tenant = app('currentTenant');

        return $tenant instanceof Tenant ? $tenant : null;
    }
}

if (! function_exists('tenant_exists')) {
    /**
     * Build an `exists` validation rule constrained to the current tenant.
     *
     * Plain `exists:table,id` queries the table directly and bypasses the
     * BelongsToTenant global scope, so a user could reference another tenant's
     * row by id. Scoping the rule to the current tenant's `tenant_id` closes
     * that cross-tenant reference. With no tenant context (CLI/platform) the
     * rule stays a plain existence check.
     *
     * Only for tables that carry a `tenant_id` column. For product_variants
     * (scoped through their product) use tenant_exists_variant().
     */
    function tenant_exists(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column);

        $tenant = current_tenant();
        if ($tenant !== null) {
            $rule->where('tenant_id', $tenant->id);
        }

        return $rule;
    }
}

if (! function_exists('tenant_exists_variant')) {
    /**
     * `exists` rule for product_variants constrained to the current tenant.
     *
     * product_variants has no tenant_id of its own — it is scoped through its
     * parent product — so we constrain product_id to the tenant's products.
     */
    function tenant_exists_variant(string $column = 'id'): Exists
    {
        $rule = Rule::exists('product_variants', $column);

        $tenant = current_tenant();
        if ($tenant !== null) {
            // where(Closure) routes to using(): the closure receives the rule's
            // query on product_variants, where we constrain product_id to a
            // subquery of the tenant's own products.
            $rule->where(static function (Builder $query) use ($tenant): void {
                $query->whereIn('product_id', static function (Builder $sub) use ($tenant): void {
                    $sub->select('id')->from('products')->where('tenant_id', $tenant->id);
                });
            });
        }

        return $rule;
    }
}

if (! function_exists('current_tenant_role')) {
    /**
     * Return the authenticated user's role in the current tenant context, or null.
     *
     * Returns null when:
     *  - No user is authenticated on this request
     *  - No tenant is active (CLI, super-admin, platform-level routes)
     *  - The user has no membership in the current tenant
     */
    function current_tenant_role(): ?string
    {
        return request()->user()?->currentRole();
    }
}
