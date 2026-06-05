<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Middleware;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantDomain;
use App\Modules\Tenancy\Support\SlugValidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant from the incoming HTTP request.
 *
 * Strategy is selected via config('tenancy.resolver'):
 *   - "subdomain"  Extract slug from leftmost DNS label of the Host header.
 *   - "path"       Extract slug from /{path_prefix}/{slug} in the URI, then
 *                  rewrite the request path to strip the prefix so downstream
 *                  routes see clean paths.
 *   - "domain"     Match the full Host header against tenant_domains (verified
 *                  status), with subdomain fallback for own-domain requests.
 *
 * Outcomes when tenant IS resolved:
 *   - Binds tenant to container as 'currentTenant'.
 *   - Sets request attribute 'tenant'.
 *   - If tenant is suspended or cancelled → 503 JSON response.
 *   - If trial is expired and no active subscription → passes through with
 *     X-Tenant-Trial-Expired: 1 header (hard gate is issue #11/#12).
 *
 * Outcomes when tenant is NOT resolved:
 *   - Routes using the 'tenant' middleware alias → abort(404).
 *   - Routes NOT using this middleware → continue unaffected.
 *
 * Caching: resolved tenant_id is cached per host/slug (TTL from config) to
 * avoid a DB round-trip on every request.
 */
final class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $resolver = (string) config('tenancy.resolver', 'subdomain');

        $tenant = match ($resolver) {
            'path' => $this->resolveFromPath($request),
            'domain' => $this->resolveFromDomain($request),
            default => $this->resolveFromSubdomain($request),
        };

        if ($tenant === null) {
            // No tenant resolved — the caller's routing table decides the response.
            // When the 'tenant' middleware is applied, Laravel will abort(404) for us
            // because this route requires a tenant context. When called without the
            // middleware alias (super-admin, marketing) the request continues normally.
            abort(404);
        }

        // Validate tenant liveness before binding.
        if ($tenant->status === 'suspended' || $tenant->status === 'cancelled') {
            Log::info('EnsureTenant: tenant access blocked', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'status' => $tenant->status,
            ]);

            return response()->json([
                'error' => 'tenant_unavailable',
                'message' => 'This account is currently '.$tenant->status.'. Please contact support.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        // Bind to container and request attribute.
        app()->instance('currentTenant', $tenant);
        $request->attributes->set('tenant', $tenant);

        $response = $next($request);

        // Soft trial-expiry gate: pass through but flag in header.
        // The hard billing gate lives in issue #11/#12 (plan gating).
        if ($this->isTrialExpiredWithoutSubscription($tenant)) {
            $response->headers->set('X-Tenant-Trial-Expired', '1');
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Resolution strategies
    // -------------------------------------------------------------------------

    /**
     * Subdomain mode: {slug}.eternova.app or {slug}.eternova.localhost
     *
     * Algorithm:
     *   1. Strip port from Host header.
     *   2. Split into labels.
     *   3. If 1-2 labels (e.g. "eternova.app", "localhost") → platform-level, return null.
     *   4. leftmost label = candidate slug.
     *   5. Validate RFC 1035 + reserved list.
     *   6. Confirm the second-to-last pair matches known base or localhost TLD combos.
     *   7. Cache lookup + return.
     */
    private function resolveFromSubdomain(Request $request): ?Tenant
    {
        $host = $this->stripPort($request->getHost());
        $labels = explode('.', $host);

        // Need at least 3 labels: {slug}.{name}.{tld}
        if (count($labels) < 3) {
            return null;
        }

        $slug = $labels[0];

        if (! $this->isOwnDomain($host)) {
            // Host is not on our base_domain or localhost variants — not a subdomain request.
            return null;
        }

        return $this->resolveSlug($slug, 'subdomain:'.$slug);
    }

    /**
     * Path mode: /{path_prefix}/{slug}/...
     *
     * When a match is found, the request URI is rewritten to remove the prefix
     * so downstream routes see clean paths without the tenant prefix.
     */
    private function resolveFromPath(Request $request): ?Tenant
    {
        $prefix = (string) config('tenancy.path_prefix', 't');
        $segments = $request->segments();

        if (($segments[0] ?? null) !== $prefix || ! isset($segments[1])) {
            return null;
        }

        $slug = $segments[1];

        $tenant = $this->resolveSlug($slug, 'path:'.$slug);

        if ($tenant !== null) {
            // Strip "/{prefix}/{slug}" from the URI so downstream routing is clean.
            $prefixToStrip = '/'.$prefix.'/'.$slug;
            $newUri = substr($request->getRequestUri(), strlen($prefixToStrip)) ?: '/';

            $request->server->set('REQUEST_URI', $newUri);
            $request->initialize(
                $request->query->all(),
                $request->request->all(),
                $request->attributes->all(),
                $request->cookies->all(),
                $request->files->all(),
                $request->server->all(),
                $request->getContent(),
            );
        }

        return $tenant;
    }

    /**
     * Domain mode: look up Host header in tenant_domains (status = 'verified').
     * Falls back to subdomain mode when the host is on our own base_domain.
     */
    private function resolveFromDomain(Request $request): ?Tenant
    {
        $host = $this->stripPort($request->getHost());

        // If the host is on our own domain, fall back to subdomain resolution.
        if ($this->isOwnDomain($host)) {
            return $this->resolveFromSubdomain($request);
        }

        $cacheKey = (string) config('tenancy.cache.prefix', 'tenancy:resolved:').'domain:'.$host;
        $ttl = (int) config('tenancy.cache.ttl', 3600);
        $enabled = (bool) config('tenancy.cache.enabled', true);

        if ($enabled) {
            $cachedId = Cache::get($cacheKey);

            if ($cachedId !== null) {
                return Tenant::find($cachedId);
            }
        }

        $domain = TenantDomain::query()
            ->where('domain', $host)
            ->where('status', 'verified')
            ->first();

        $tenant = $domain?->tenant;

        if ($enabled && $tenant !== null) {
            Cache::put($cacheKey, $tenant->id, $ttl);
        }

        return $tenant;
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * Validate slug format + reserved list, then perform cached DB lookup.
     */
    private function resolveSlug(string $slug, string $cacheKeySuffix): ?Tenant
    {
        if (! SlugValidator::isValid($slug)) {
            Log::debug('EnsureTenant: invalid slug format rejected', ['slug' => $slug]);
            abort(404);
        }

        if (SlugValidator::isReserved($slug)) {
            Log::debug('EnsureTenant: reserved slug rejected', ['slug' => $slug]);
            abort(404);
        }

        $prefix = (string) config('tenancy.cache.prefix', 'tenancy:resolved:');
        $ttl = (int) config('tenancy.cache.ttl', 3600);
        $enabled = (bool) config('tenancy.cache.enabled', true);

        $cacheKey = $prefix.$cacheKeySuffix;

        if ($enabled) {
            $cachedId = Cache::get($cacheKey);

            if ($cachedId !== null) {
                return Tenant::find($cachedId);
            }
        }

        $tenant = Tenant::findBySlug($slug);

        if ($enabled && $tenant !== null) {
            Cache::put($cacheKey, $tenant->id, $ttl);
        }

        return $tenant;
    }

    /**
     * Return true when $host ends with our base_domain or a localhost-TLD variant.
     *
     * Examples (base_domain = "eternova.app"):
     *   "rosa-eterna.eternova.app"       → true
     *   "rosa-eterna.eternova.localhost"  → true  (localhost TLD)
     *   "rosa-eterna.eternova.test"       → true  (test TLD)
     *   "eternova.app"                    → true  (root, but < 3 labels — filtered upstream)
     *   "rosaeterna.com"                  → false
     */
    private function isOwnDomain(string $host): bool
    {
        $baseDomain = (string) config('tenancy.base_domain', 'eternova.app');
        $localTlds = (array) config('tenancy.localhost_tlds', ['localhost', 'test']);

        // Exact match or subdomain of base_domain.
        if ($host === $baseDomain || str_ends_with($host, '.'.$baseDomain)) {
            return true;
        }

        // Localhost/test variants: {slug}.{base-without-tld}.{localTld}
        // e.g. rosa-eterna.eternova.localhost
        $baseParts = explode('.', $baseDomain);
        // Pop the production TLD ("app") and get the SaaS name part ("eternova").
        array_pop($baseParts);
        $baseName = implode('.', $baseParts); // "eternova" (or "sub.eternova" if nested)

        foreach ($localTlds as $tld) {
            $localBase = $baseName.'.'.$tld; // "eternova.localhost"
            if ($host === $localBase || str_ends_with($host, '.'.$localBase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip port number from a hostname string.
     * "rosa-eterna.eternova.app:8080" → "rosa-eterna.eternova.app"
     */
    private function stripPort(string $host): string
    {
        return (string) strtok($host, ':');
    }

    /**
     * Return true when the tenant's trial has expired AND they have no active subscription.
     *
     * "No active subscription" is intentionally broad at this stage — the Billing module
     * (issue #9) will add the Subscription model. For now, a tenant with trial_ends_at
     * in the past is considered expired when they have no subscriptions rows at all.
     */
    private function isTrialExpiredWithoutSubscription(Tenant $tenant): bool
    {
        if ($tenant->trial_ends_at === null) {
            // No trial set — not a trial account.
            return false;
        }

        if ($tenant->trial_ends_at->isFuture()) {
            // Trial is still active.
            return false;
        }

        // Trial has expired; check for an active subscription.
        // The Billing module may not be migrated yet in all envs; guard with try/catch.
        try {
            return ! $tenant->subscriptions()
                ->where('status', 'active')
                ->exists();
        } catch (\Throwable) {
            // Subscriptions table doesn't exist yet (pre-billing migration) — assume expired.
            return true;
        }
    }
}
