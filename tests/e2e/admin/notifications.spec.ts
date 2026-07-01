import { test, expect, type APIRequestContext } from '@playwright/test'

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

/**
 * Derive the tenant-scoped base URL for use with page.goto (Chromium navigation).
 * Chromium applies the RFC 6761 loopback rule for any hostname ending in .localhost,
 * so the browser can navigate to http://{slug}.eternova.localhost directly.
 *
 * Note: Node.js's HTTP client (used by Playwright's request fixture) does NOT
 * resolve *.eternova.localhost — see branding-darkmode.spec.ts for the same
 * pattern. We use Host header override for request context calls instead.
 */
function tenantBrowserURL(slug: string): string {
    const url = new URL(baseURL)
    // Replace just the hostname, keep the port if present.
    const host = url.host.replace('localhost', `${slug}.eternova.localhost`)
    return `${url.protocol}//${host}`
}

/**
 * Register a new user and provision a tenant for them.
 * Returns the Bearer token (plain_text_token from the register response) and slug.
 *
 * The register endpoint requires Password::min(8)->mixedCase()->numbers().
 * The login endpoint does NOT issue a Bearer token; use register for that.
 */
async function createUserWithTenant(
    request: APIRequestContext,
): Promise<{ token: string; slug: string }> {
    const ts = Date.now()
    const rand = Math.floor(Math.random() * 1e6)
    const email = `notif-${ts}-${rand}@example.com`
    const password = 'Password123'
    const slug = `e2e-notif-${ts}`

    const reg = await request.post(`${baseURL}/api/v1/auth/register`, {
        data: { name: 'Notif Test User', email, password },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })
    if (!reg.ok()) throw new Error(`register failed ${reg.status()}: ${await reg.text()}`)
    const token = (await reg.json()).plain_text_token as string

    const tenantRes = await request.post(`${baseURL}/api/v1/tenants`, {
        data: {
            slug,
            name: 'E2E Notif Shop',
            business_name: 'E2E Notif Boutique',
            country_code: 'SV',
            currency: 'USD',
            language: 'es',
            timezone: 'America/El_Salvador',
        },
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
        },
    })
    if (!tenantRes.ok()) {
        throw new Error(`tenant creation failed ${tenantRes.status()}: ${await tenantRes.text()}`)
    }

    return { token, slug }
}

/**
 * Seed two test notifications for the authenticated user.
 *
 * The seed endpoint requires the `tenant` middleware, so we call it via the
 * plain localhost URL but override the Host header to the tenant subdomain.
 * This fakes the subdomain to EnsureTenant without needing DNS resolution
 * (which Node.js's HTTP client can't do for *.eternova.localhost).
 */
async function seedNotifications(
    request: APIRequestContext,
    token: string,
    slug: string,
): Promise<number> {
    const tenantHost = `${slug}.eternova.localhost`
    const res = await request.post(`${baseURL}/api/v1/testing/notifications`, {
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${token}`,
            Host: tenantHost,
        },
    })
    if (!res.ok()) throw new Error(`seed failed ${res.status()}: ${await res.text()}`)
    const { data } = await res.json()
    return data.created as number
}

test.describe('Admin notifications bell', () => {
    test('shows unread badge, lists notifications, marks all read', async ({ page, request }) => {
        const { token, slug } = await createUserWithTenant(request)
        const created = await seedNotifications(request, token, slug)
        expect(created).toBe(2)

        // Navigate using Chromium, which resolves *.eternova.localhost via RFC 6761.
        // The Authorization header must be set before page.goto so all SPA bootstrapping
        // calls (/api/v1/me, /api/v1/notifications/unread-count) are authenticated.
        await page.setExtraHTTPHeaders({ Authorization: `Bearer ${token}` })
        await page.goto(`${tenantBrowserURL(slug)}/admin/dashboard`)

        // Badge reflects the 2 seeded unread notifications.
        await expect(page.getByTestId('notif-badge')).toHaveText('2')

        // Open the panel.
        await page.getByTestId('notif-bell').click()
        await expect(page.getByTestId('notif-panel')).toBeVisible()

        // Both presenter titles render (exercises >1 presenter branch).
        await expect(page.getByText('Stock bajo')).toBeVisible()
        await expect(page.getByText('Factura lista')).toBeVisible()

        // Mark all read -> badge disappears.
        await page.getByTestId('notif-mark-all').click()
        await expect(page.getByTestId('notif-badge')).toHaveCount(0)
    })

    test('bell is reachable on mobile viewport', async ({ page, request }) => {
        const { token, slug } = await createUserWithTenant(request)
        await seedNotifications(request, token, slug)

        await page.setViewportSize({ width: 375, height: 667 })
        await page.setExtraHTTPHeaders({ Authorization: `Bearer ${token}` })
        await page.goto(`${tenantBrowserURL(slug)}/admin/dashboard`)

        const bell = page.getByTestId('notif-bell')
        await expect(bell).toBeVisible()
        await bell.click()
        await expect(page.getByTestId('notif-panel')).toBeVisible()
    })
})
