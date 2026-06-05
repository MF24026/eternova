<?php

declare(strict_types=1);

use App\Modules\Tenancy\Models\Tenant;

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
