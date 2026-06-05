<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Stateless helper for tenant slug validation.
 *
 * Used by EnsureTenant middleware and the /api/v1/tenants/check-slug endpoint
 * (issue #13). Keep this a pure static utility — no constructor injection,
 * no state — so callers across both HTTP and CLI contexts can use it freely.
 */
final class SlugValidator
{
    /**
     * Return true when the slug satisfies all RFC 1035 hostname label rules
     * and the configured min/max length constraints.
     *
     * Rules enforced:
     *   - Lowercase alphanumeric characters and interior dashes only.
     *   - Must NOT start or end with a dash.
     *   - Length between config('tenancy.slug.min_length') and config('tenancy.slug.max_length').
     *
     * Note: uppercase is intentionally rejected — DNS labels are case-insensitive,
     * but we normalise to lowercase at signup to prevent {Slug} vs {slug} duplicates.
     */
    public static function isValid(string $slug): bool
    {
        $minLength = (int) config('tenancy.slug.min_length', 3);
        $maxLength = (int) config('tenancy.slug.max_length', 63);
        $pattern = (string) config('tenancy.slug.pattern', '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/');

        $length = strlen($slug);

        if ($length < $minLength || $length > $maxLength) {
            return false;
        }

        return (bool) preg_match($pattern, $slug);
    }

    /**
     * Return true when the slug appears in the reserved_subdomains table.
     *
     * Results are cached for 1 hour to avoid a DB query on every inbound request.
     * The cache stores a boolean per slug key. A miss means "not reserved".
     *
     * The comparison is intentionally case-insensitive (LOWER() in SQL) because
     * slugs are always stored lowercase but defensive lowercasing here prevents
     * surprises if the caller passes a mixed-case value.
     */
    public static function isReserved(string $slug): bool
    {
        $slug = strtolower($slug);

        $cacheKey = 'tenancy:reserved:'.$slug;
        $cacheTtl = 3600;

        /** @var bool|null $cached */
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        $exists = DB::table('reserved_subdomains')
            ->whereRaw('LOWER(subdomain) = ?', [$slug])
            ->exists();

        Cache::put($cacheKey, $exists, $cacheTtl);

        return $exists;
    }
}
