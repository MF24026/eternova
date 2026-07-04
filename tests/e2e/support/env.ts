/**
 * Single source of truth for E2E target URLs.
 *
 * Inside the Sail container the app listens on port 80; the host maps that to
 * 8080 (see CLAUDE.md port remap). playwright.config.ts sets `use.baseURL` to
 * the same default below — keep the two in sync. Override both from outside the
 * container with `PLAYWRIGHT_BASE_URL=http://localhost:8080`.
 *
 * Specs MUST import `BASE_URL` / `tenantBaseURL` from here instead of
 * redefining their own default, so the port lives in exactly one place.
 */
const DEFAULT_BASE_URL = 'http://localhost'

export const BASE_URL = (process.env.PLAYWRIGHT_BASE_URL ?? DEFAULT_BASE_URL).replace(/\/$/, '')

/**
 * Build the origin for a tenant's subdomain, preserving whatever host:port the
 * base URL uses (localhost in-container, localhost:8080 from the host).
 *
 *   tenantBaseURL('rosa-eterna') // http://rosa-eterna.eternova.localhost  (in-container)
 *                                // http://rosa-eterna.eternova.localhost:8080 (host)
 */
export function tenantBaseURL(slug: string): string {
    const url = new URL(BASE_URL)
    url.hostname = `${slug}.eternova.${url.hostname}`
    return url.origin
}
