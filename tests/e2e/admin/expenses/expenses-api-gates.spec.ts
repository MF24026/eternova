import { test, expect, type Page } from '@playwright/test'

/**
 * Expenses Admin API — auth gates + multi-tenant boundary (S6-E4).
 *
 * Focuses on HTTP-layer behaviour: unauthenticated access, authenticated
 * access with correct response shape, and the category endpoint. Exercised
 * via in-page fetch() against the seeded demo tenant (rosa-eterna).
 *
 * Why in-page fetch (not page.request):
 *   Node's HTTP client cannot resolve *.eternova.localhost — only Chromium
 *   applies the RFC 6761 loopback rule. page.evaluate() runs inside the
 *   browser context where the host resolves and the session cookie is sent
 *   automatically (same-origin, credentials: 'include').
 *
 * Shape-based assertions (not count-based) because the expense seeder
 * lands in E8. We verify HTTP semantics and envelope structure only.
 *
 * Requires DemoTenantsSeeder:
 *   - Tenant slug: rosa-eterna
 *   - Owner: caro@rosaeterna.com / DemoPro123!
 *   - 5 default expense categories seeded by ExpenseCategoriesSeeder (E1)
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
 * For state-mutating methods (PATCH, POST, PUT) we read the XSRF-TOKEN
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

test.describe('Expenses API — auth gates', () => {
    test('unauthenticated GET /api/v1/expenses returns 401', async ({ page }) => {
        await page.goto(TENANT_BASE)

        const result = await apiFetch(page, '/api/v1/expenses')

        expect(result.status, 'Expected 401 for unauthenticated request').toBe(401)
    })

    test('authenticated owner gets 200 + correct envelope shape', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/expenses')

        expect(result.status, 'Expected 200 for authenticated owner').toBe(200)

        const body = result.body as Record<string, unknown>

        // Standard pagination envelope
        expect(body).toHaveProperty('data')
        expect(body).toHaveProperty('links')
        expect(body).toHaveProperty('meta')

        // period_total_cents must be present and be a number
        expect(body).toHaveProperty('period_total_cents')
        expect(
            typeof body['period_total_cents'],
            'period_total_cents must be a number',
        ).toBe('number')
    })

    test('response meta includes tenant_id for authenticated request', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/expenses')
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

    test('bogus expense id returns 404', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/expenses/999999999')

        expect(result.status, 'Expected 404 for non-existent expense id').toBe(404)
    })

    test('unauthenticated GET /api/v1/expenses/categories returns 401', async ({ page }) => {
        await page.goto(TENANT_BASE)

        const result = await apiFetch(page, '/api/v1/expenses/categories')

        expect(result.status, 'Expected 401 for unauthenticated categories request').toBe(401)
    })

    test('GET /api/v1/expenses/categories returns 200 with the 5 seeded default categories', async ({
        page,
    }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/expenses/categories')

        expect(result.status, 'Expected 200 for categories endpoint').toBe(200)

        const body = result.body as Record<string, unknown>
        expect(body).toHaveProperty('data')

        const data = body['data'] as unknown[]
        expect(Array.isArray(data), 'data must be an array').toBeTruthy()
        // ExpenseCategoriesSeeder seeds exactly 5 default categories per tenant
        expect(data.length, 'Expected 5 seeded default categories').toBeGreaterThanOrEqual(5)

        // Each category must have the required fields
        const firstCategory = data[0] as Record<string, unknown>
        expect(firstCategory).toHaveProperty('id')
        expect(firstCategory).toHaveProperty('name')
        expect(firstCategory).toHaveProperty('type')
        expect(firstCategory).toHaveProperty('is_active')
    })

    test('PATCH /api/v1/expenses/{id} with unknown id returns 404', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/expenses/999999999', {
            method: 'PATCH',
            body: { amount_cents: 5000 },
        })

        expect(result.status, 'Expected 404 for unknown expense on update endpoint').toBe(404)
    })
})
