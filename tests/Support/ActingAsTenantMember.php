<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Testing\TestResponse;

/**
 * Convenience helpers for Feature tests that hit tenant-scoped API routes.
 *
 * The EnsureTenant middleware resolves the tenant from the Host header using
 * the subdomain strategy ({slug}.eternova.app). Laravel's test client extracts
 * the host from the URL, NOT from withServerVariables — Symfony's
 * SymfonyRequest::create() overrides HTTP_HOST with the URL's own host component.
 *
 * Therefore, all tenant-scoped requests must use full URLs:
 *   http://{tenant-slug}.eternova.app/api/v1/...
 *
 * This trait provides helpers that prepend the correct host automatically.
 */
trait ActingAsTenantMember
{
    /**
     * Build the base URL for a given tenant using its slug.
     *
     * Uses TENANT_BASE_DOMAIN from config (default: eternova.app).
     */
    protected function tenantUrl(Tenant $tenant, string $path): string
    {
        $baseDomain = config('tenancy.base_domain', 'eternova.app');
        $path = ltrim($path, '/');

        return "http://{$tenant->slug}.{$baseDomain}/{$path}";
    }

    /**
     * Perform a GET request scoped to the given tenant.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function tenantGetJson(Tenant $tenant, User $user, string $uri, array $headers = []): TestResponse
    {
        return $this->actingAs($user)->getJson($this->tenantUrl($tenant, $uri), $headers);
    }

    /**
     * Perform a POST request scoped to the given tenant.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $headers
     */
    protected function tenantPostJson(Tenant $tenant, User $user, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($user)->postJson($this->tenantUrl($tenant, $uri), $data, $headers);
    }

    /**
     * Perform a PATCH request scoped to the given tenant.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $headers
     */
    protected function tenantPatchJson(Tenant $tenant, User $user, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($user)->patchJson($this->tenantUrl($tenant, $uri), $data, $headers);
    }

    /**
     * Perform a PUT request scoped to the given tenant.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $headers
     */
    protected function tenantPutJson(Tenant $tenant, User $user, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->actingAs($user)->putJson($this->tenantUrl($tenant, $uri), $data, $headers);
    }

    /**
     * Perform a DELETE request scoped to the given tenant.
     *
     * @param  array<string, mixed>  $headers
     */
    protected function tenantDeleteJson(Tenant $tenant, User $user, string $uri, array $headers = []): TestResponse
    {
        return $this->actingAs($user)->deleteJson($this->tenantUrl($tenant, $uri), $headers);
    }
}
