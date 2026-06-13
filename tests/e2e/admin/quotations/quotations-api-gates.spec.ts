import { test, expect, type Page } from '@playwright/test'

/**
 * Quotations Admin API — auth gates + multi-tenant boundary (S7-E3).
 *
 * There is no Quotations UI yet (that is E6/E7). This spec focuses on the HTTP
 * layer: unauthenticated access, authenticated access with correct response
 * shape, and cross-tenant isolation — exercised via in-page fetch() against
 * the seeded demo tenant.
 *
 * Why in-page fetch (not page.request):
 *   Node's HTTP client cannot resolve *.eternova.localhost — only Chromium
 *   applies the RFC 6761 loopback rule. page.evaluate() runs inside the
 *   browser context where the host resolves and the session cookie is sent
 *   automatically (same-origin, credentials: 'include').
 *
 * Shape-based assertions (not count-based) because the quotations seeder
 * lands in E8. We verify structure and HTTP semantics only.
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
 * For state-mutating methods (POST, PUT, PATCH, DELETE) we read the
 * XSRF-TOKEN cookie from document.cookie and send it in the X-XSRF-TOKEN
 * header so Sanctum's SPA CSRF protection does not reject the request with 419.
 */
async function apiFetch(
    page: Page,
    path: string,
    options: { method?: string; body?: unknown } = {},
): Promise<{ status: number; ok: boolean; body: unknown }> {
    return page.evaluate(
        async ([p, method, body]) => {
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

test.describe('Quotations API — auth gates', () => {
    test('unauthenticated GET /api/v1/quotations returns 401', async ({ page }) => {
        await page.goto(TENANT_BASE)

        const result = await apiFetch(page, '/api/v1/quotations')

        expect(result.status, 'Expected 401 for unauthenticated request').toBe(401)
    })

    test('authenticated owner gets 200 + correct status_counts shape', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/quotations')

        expect(result.status, 'Expected 200 for authenticated owner').toBe(200)

        const body = result.body as Record<string, unknown>

        // Standard pagination envelope
        expect(body).toHaveProperty('data')
        expect(body).toHaveProperty('links')
        expect(body).toHaveProperty('meta')

        // status_counts must be present with all five known statuses
        expect(body).toHaveProperty('status_counts')
        const counts = body['status_counts'] as Record<string, number>
        for (const status of ['draft', 'sent', 'accepted', 'rejected', 'expired']) {
            expect(typeof counts[status], `status_counts.${status} must be a number`).toBe('number')
        }
    })

    test('bogus quotation id returns 404', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/quotations/999999999')

        expect(result.status, 'Expected 404 for non-existent quotation id').toBe(404)
    })

    test('response meta includes tenant_id for authenticated request', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/quotations')
        expect(result.ok).toBeTruthy()

        const body = result.body as Record<string, unknown>
        const meta = body['meta'] as Record<string, unknown>
        expect(meta).toHaveProperty('tenant_id')

        const tenantIdValue = meta['tenant_id']
        const tenantIdStr =
            typeof tenantIdValue === 'string'
                ? tenantIdValue
                : typeof tenantIdValue === 'object' && tenantIdValue !== null
                  ? String(Object.values(tenantIdValue)[0] ?? '')
                  : ''
        expect(tenantIdStr.length, 'tenant_id should be a non-empty ULID').toBeGreaterThan(0)
    })

    test('POST /api/v1/quotations without items returns 422', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/quotations', {
            method: 'POST',
            body: {
                issue_date: new Date().toISOString().slice(0, 10),
                // No items — must fail validation
            },
        })

        expect(result.status, 'Expected 422 for missing items').toBe(422)

        const body = result.body as Record<string, unknown>
        expect(body).toHaveProperty('errors')
    })

    test('POST /api/v1/quotations/{id}/send with unknown id returns 404', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/quotations/999999999/send', {
            method: 'POST',
        })

        expect(result.status, 'Expected 404 for unknown quotation on send endpoint').toBe(404)
    })

    test('unauthenticated POST /api/v1/quotations returns 401', async ({ page }) => {
        await page.goto(TENANT_BASE)

        const result = await apiFetch(page, '/api/v1/quotations', {
            method: 'POST',
            body: {
                issue_date: new Date().toISOString().slice(0, 10),
                items: [{ description: 'Test', quantity: 1, unit_price_cents: 1000 }],
            },
        })

        expect(result.status, 'Expected 401 for unauthenticated POST').toBe(401)
    })
})
