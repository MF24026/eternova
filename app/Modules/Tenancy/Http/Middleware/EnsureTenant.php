<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    /**
     * Resolve the current tenant from:
     *  1. Subdomain: {slug}.eternova.app
     *  2. Authenticated user's tenant_id
     *
     * Binds the resolved Tenant to the service container as 'currentTenant'.
     * Aborts with 404 when no valid tenant can be resolved.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolveFromSubdomain($request)
            ?? $this->resolveFromAuthUser($request);

        if ($tenant === null || ! $tenant->isActive()) {
            abort(404);
        }

        app()->instance('currentTenant', $tenant);

        return $next($request);
    }

    /**
     * Attempt to resolve tenant from the request subdomain.
     *
     * Expects hostnames like: demo.eternova.app
     * The first segment before the first dot is treated as the slug.
     */
    private function resolveFromSubdomain(Request $request): ?Tenant
    {
        $host = $request->getHost();

        // Strip port if present
        $host = strtok($host, ':');

        $parts = explode('.', $host);

        // We need at least 3 parts: {slug}.eternova.app
        if (count($parts) < 3) {
            return null;
        }

        $slug = $parts[0];

        return Tenant::findBySlug($slug);
    }

    /**
     * Attempt to resolve tenant from the authenticated user.
     *
     * Super-admins (users without a tenant_id) are excluded intentionally —
     * they should not land on tenant-scoped routes.
     */
    private function resolveFromAuthUser(Request $request): ?Tenant
    {
        $user = $request->user();

        if ($user === null || empty($user->tenant_id)) {
            return null;
        }

        return Tenant::find($user->tenant_id);
    }
}
