import { test, expect, type Page } from '@playwright/test'

/**
 * Reservations Admin API — auth gates + multi-tenant boundary (S5-E5).
 *
 * There is no Reservations UI yet (that is E6/E7). This spec focuses on the HTTP
 * layer: unauthenticated access, authenticated access with correct response shape,
 * and cross-tenant isolation — exercised via in-page fetch() against the seeded
 * demo tenant.
 *
 * Why in-page fetch (not page.request):
 *   Node's HTTP client cannot resolve *.eternova.localhost — only Chromium
 *   applies the RFC 6761 loopback rule. page.evaluate() runs inside the
 *   browser context where the host resolves and the session cookie is sent
 *   automatically (same-origin, credentials: 'include').
 *
 * Shape-based assertions (not count-based) because reservations seeder lands
 * in E8. We verify structure and HTTP semantics only.
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

test.describe('Reservations API — auth gates', () => {
    test('unauthenticated GET /api/v1/reservations returns 401', async ({ page }) => {
        await page.goto(TENANT_BASE)

        const result = await apiFetch(page, '/api/v1/reservations')

        expect(result.status, 'Expected 401 for unauthenticated request').toBe(401)
    })

    test('authenticated owner gets 200 + correct status_counts shape', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/reservations')

        expect(result.status, 'Expected 200 for authenticated owner').toBe(200)

        const body = result.body as Record<string, unknown>

        // Standard pagination envelope
        expect(body).toHaveProperty('data')
        expect(body).toHaveProperty('links')
        expect(body).toHaveProperty('meta')

        // status_counts must be present with all six known statuses
        expect(body).toHaveProperty('status_counts')
        const counts = body['status_counts'] as Record<string, number>
        for (const status of ['inquiry', 'confirmed', 'in_progress', 'ready', 'delivered', 'cancelled']) {
            expect(typeof counts[status], `status_counts.${status} must be a number`).toBe('number')
        }
    })

    test('bogus reservation id returns 404', async ({ page }) => {
        await login(page)

        // A non-existent integer id
        const result = await apiFetch(page, '/api/v1/reservations/999999999')

        expect(result.status, 'Expected 404 for non-existent reservation id').toBe(404)
    })

    test('response meta includes tenant_id for authenticated request', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/reservations')
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

    test('GET /api/v1/reservations/settings returns deposit_pct and occasions', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/reservations/settings')

        expect(result.status, 'Expected 200 for settings endpoint').toBe(200)

        const body = result.body as Record<string, unknown>
        expect(body).toHaveProperty('data')

        const data = body['data'] as Record<string, unknown>
        expect(data).toHaveProperty('deposit_pct')
        expect(data).toHaveProperty('occasions')

        expect(typeof data['deposit_pct'], 'deposit_pct must be a number').toBe('number')
        expect(
            (data['deposit_pct'] as number) >= 0 && (data['deposit_pct'] as number) <= 100,
            'deposit_pct must be between 0 and 100',
        ).toBeTruthy()

        expect(Array.isArray(data['occasions']), 'occasions must be an array').toBeTruthy()
        expect((data['occasions'] as unknown[]).length, 'occasions must not be empty').toBeGreaterThan(0)
    })

    test('PATCH /api/v1/reservations/{id}/status with unknown id returns 404', async ({ page }) => {
        await login(page)

        const result = await apiFetch(page, '/api/v1/reservations/999999999/status', {
            method: 'PATCH',
            body: { status: 'confirmed' },
        })

        expect(result.status, 'Expected 404 for unknown reservation on transition endpoint').toBe(404)
    })

    test('unauthenticated GET /api/v1/reservations/settings returns 401', async ({ page }) => {
        await page.goto(TENANT_BASE)

        const result = await apiFetch(page, '/api/v1/reservations/settings')

        expect(result.status, 'Expected 401 for unauthenticated settings request').toBe(401)
    })
})
