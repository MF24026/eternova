<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tenant Resolution Strategy
    |--------------------------------------------------------------------------
    |
    | Controls how the EnsureTenant middleware identifies the current tenant
    | from an incoming HTTP request.
    |
    | Supported values:
    |   "subdomain" — extract slug from leftmost DNS label of the Host header
    |   "path"      — extract slug from /{path_prefix}/{slug}/... in the URI
    |   "domain"    — match full Host header against tenant_domains table,
    |                 with subdomain fallback for own-domain requests
    |
    */
    'resolver' => env('TENANT_RESOLVER', 'subdomain'),

    /*
    |--------------------------------------------------------------------------
    | Base Domain
    |--------------------------------------------------------------------------
    |
    | The root domain of the SaaS platform. Tenant subdomains are formed as
    | {slug}.{base_domain}. In production this is "eternova.app"; in local dev
    | it is typically "eternova.localhost" (RFC 6761 auto-resolves to 127.0.0.1).
    |
    */
    'base_domain' => env('TENANT_BASE_DOMAIN', 'eternova.app'),

    /*
    |--------------------------------------------------------------------------
    | Localhost TLDs for Development
    |--------------------------------------------------------------------------
    |
    | TLD suffixes that are treated as local dev equivalents of the base_domain.
    | When the host is {slug}.eternova.{tld}, the resolver extracts {slug} just
    | like it would for {slug}.eternova.app in production.
    |
    */
    'localhost_tlds' => ['localhost', 'test'],

    /*
    |--------------------------------------------------------------------------
    | Path Mode Prefix
    |--------------------------------------------------------------------------
    |
    | When resolver = "path", tenant routes are prefixed with this segment.
    | Example: /t/rosa-eterna/admin/dashboard → tenant "rosa-eterna".
    |
    */
    'path_prefix' => 't',

    /*
    |--------------------------------------------------------------------------
    | Slug Validation Rules
    |--------------------------------------------------------------------------
    |
    | Constraints applied to tenant slugs. These must match RFC 1035 hostname
    | label rules (lowercase alphanumeric + interior dashes, no leading/trailing
    | dash, max 63 chars per label).
    |
    */
    'slug' => [
        'min_length' => 3,
        'max_length' => 63,
        // RFC 1035 §2.3.4: label must start and end with alphanumeric;
        // interior chars may include dashes.
        'pattern' => '/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/',
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolution Cache
    |--------------------------------------------------------------------------
    |
    | Tenant lookups can be cached to avoid a DB round-trip on every request.
    | The cache stores tenant_id (ULID string) keyed by host or slug.
    | A short TTL is fine because Tenant::findBySlug() is a single PK lookup.
    |
    */
    'cache' => [
        'enabled' => true,
        'ttl' => 3600, // seconds
        'prefix' => 'tenancy:resolved:',
    ],

];
