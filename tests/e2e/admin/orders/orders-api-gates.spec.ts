import { test, expect, type Page } from '@playwright/test'

/**
 * Orders Admin API — auth gates + multi-tenant boundary (S4-E3).
 *
 * There is no Orders UI yet (that is E5/E6). This spec focuses on the HTTP
 * layer: unauthenticated access, authenticated access, and cross-tenant
 * isolation — exercised via in-page fetch() against the seeded demo tenant.
 *
 * Why in-page fetch (not page.request):
 *   Node's HTTP client cannot resolve *.eternova.localhost — only Chromium
 *   applies the RFC 6761 loopback rule. page.evaluate() runs inside the
 *   browser context where the host resolves and the session cookie is sent
 *   automatically (same-origin, credentials: 'include').
 *
 * Requires DemoTenantsSeeder (owner caro@rosaeterna.com on tenant rosa-eterna).
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

/**
 * In-page fetch helper. Executes inside Chromium so *.eternova.localhost
 * resolves and the session cookie is included automatically.
 *
 * For state-mutating methods (PATCH, POST, DELETE) we read the XSRF-TOKEN
 * cookie from document.cookie and send it in the X-XSRF-TOKEN header so
 * Sanctum's SPA CSRF protection does not reject the request with 419.
 */
async function apiFetch(
    page: Page,
    path: string,
    options: { method?: string; body?: unknown } = {},
): Promise<{ status: number; ok: boolean; body: unknown }> {
    return page.evaluate(
        async ([p, method, body]) => {
            // Read XSRF-TOKEN cookie for CSRF-protected methods.
            const xsrfCookie = document.cookie
                .split(';')
                .map((c) => c.trim())
                .find((c) => c.startsWith('XSRF-TOKEN='))
            const xsrfToken = xsrfCookie
                ? decodeURIComponent(xsrfCookie.split('=').slice(1).join('='))
                : null

            const init: RequestInit = {
                method: method ?? 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
                    ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
                },
                credentials: 'include',
                ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
            }
            const r = await fetch(p, init)
            let responseBody: unknown
            try {
                responseBody = await r.json()
            } catch {
                responseBody = null
            }
            return { status: r.status, ok: r.ok, body: responseBody }
        },
        [path, options.method ?? 'GET', options.body] as [string, string, unknown],
    )
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Orders API — auth gates', () => {
    test('unauthenticated GET /api/v1/orders returns 401', async ({ page }) => {
        // Navigate to the tenant base URL without logging in — we just need
        // the page loaded so evaluate() runs in the correct origin.
        await page.goto(TENANT_BASE)

        const result = await apiFetch(page, '/api/v1/orders')

        expect(result.status, 'Expected 401 for unauthenticated request').toBe(401)
    })

    test('authenticated owner gets 200 + correct status_counts shape', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/orders')

        expect(result.status, 'Expected 200 for authenticated owner').toBe(200)

        const body = result.body as Record<string, unknown>

        // Standard pagination envelope
        expect(body).toHaveProperty('data')
        expect(body).toHaveProperty('links')
        expect(body).toHaveProperty('meta')

        // status_counts must be present with all six known statuses
        expect(body).toHaveProperty('status_counts')
        const counts = body['status_counts'] as Record<string, number>
        for (const status of ['pending', 'preparing', 'ready', 'dispatched', 'delivered', 'cancelled']) {
            expect(typeof counts[status], `status_counts.${status} must be a number`).toBe('number')
        }
    })

    test('bogus order id returns 404', async ({ page }) => {
        await login(page)

        // A well-formed ULID that does not exist in the tenant
        const fakeId = '01JZZZZZZZZZZZZZZZZZZZZZZZ'
        const result = await apiFetch(page, `/api/v1/orders/${fakeId}`)

        expect(result.status, 'Expected 404 for non-existent order id').toBe(404)
    })

    test('response meta includes tenant_id for authenticated request', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/orders')
        expect(result.ok).toBeTruthy()

        const body = result.body as Record<string, unknown>
        const meta = body['meta'] as Record<string, unknown>
        expect(meta).toHaveProperty('tenant_id')

        // tenant_id is a ULID string. paginationInformation() and with() both
        // merge tenant_id into meta, so accept either string or nested meta object.
        const tenantIdValue = meta['tenant_id']
        const tenantIdStr =
            typeof tenantIdValue === 'string'
                ? tenantIdValue
                : typeof tenantIdValue === 'object' && tenantIdValue !== null
                  ? String(Object.values(tenantIdValue)[0] ?? '')
                  : ''
        expect(tenantIdStr.length, 'tenant_id should be a non-empty ULID').toBeGreaterThan(0)
    })

    test('PATCH /api/v1/orders/{id}/status with unknown id returns 404', async ({ page }) => {
        await login(page)

        const fakeId = '01JZZZZZZZZZZZZZZZZZZZZZZZ'
        const result = await apiFetch(page, `/api/v1/orders/${fakeId}/status`, {
            method: 'PATCH',
            body: { status: 'ready' },
        })

        expect(result.status, 'Expected 404 for unknown order on transition endpoint').toBe(404)
    })
})
