import { test, expect, type Page } from '@playwright/test'

/**
 * Quotation Detail Page — S7-E8.
 *
 * Exercises QuotationDetailPage: rendering, PDF action, status transitions
 * (send / accept / reject), accept-with-convert-to-order, edit-draft, and the
 * status-history timeline.
 *
 * Each mutating test creates its OWN quotation via the API first so the tests
 * are independent and repeatable against the shared dev DB.
 *
 * Requires DemoTenantsSeeder:
 *   - Tenant slug: rosa-eterna
 *   - Owner: caro@rosaeterna.com / DemoPro123!
 *
 * Run with: --workers=1
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

// ── Helpers ───────────────────────────────────────────────────────────────────

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function waitForDetail(page: Page): Promise<void> {
    await expect(
        page.locator('[data-testid="quotation-detail"]'),
        'Detail view should render',
    ).toBeVisible({ timeout: 15_000 })
}

/**
 * In-page fetch helper — runs inside Chromium so *.eternova.localhost resolves
 * and the session cookie + XSRF token are sent automatically.
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

/**
 * Create a draft quotation via the API and return its id. Skips the test if the
 * create fails (e.g. seed missing) rather than failing spuriously.
 */
async function createDraft(
    page: Page,
    overrides: Record<string, unknown> = {},
): Promise<number | null> {
    const result = await apiFetch(page, '/api/v1/quotations', {
        method: 'POST',
        body: {
            issue_date: new Date().toISOString().slice(0, 10),
            items: [
                { description: 'Servicio de detalle E2E', quantity: 2, unit_price_cents: 4500 },
            ],
            ...overrides,
        },
    })

    if (!result.ok) return null
    return ((result.body as Record<string, unknown>).data as { id: number }).id
}

/** Transition a quotation through an API action (send/accept/reject). */
async function apiTransition(
    page: Page,
    id: number,
    action: 'send' | 'accept' | 'reject',
    body: Record<string, unknown> = {},
): Promise<boolean> {
    const result = await apiFetch(page, `/api/v1/quotations/${id}/${action}`, {
        method: 'POST',
        body,
    })
    return result.ok
}

async function gotoDetail(page: Page, id: number): Promise<void> {
    await page.goto(`${TENANT_BASE}/admin/quotations/${id}`)
    await waitForDetail(page)
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe('Quotation detail — rendering (S7-E8)', () => {

    test('renders header, line items, totals and timeline for a draft', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }

        await gotoDetail(page, id)

        // Status badge reads Borrador
        await expect(page.locator('[data-testid="quotation-status-badge"]')).toContainText('Borrador')

        // Line items table renders the seeded line
        await expect(page.locator('[data-testid="quotation-items-table"]')).toBeVisible()
        await expect(page.locator('[data-testid="quotation-items-table"]')).toContainText('Servicio de detalle E2E')

        // Total: 2 * 4500 = 9000 cents = $90.00 (no discount, no tax on this draft)
        await expect(page.locator('[data-testid="quotation-total"]')).toContainText('90.00')

        // Timeline shows the creation entry
        await expect(page.locator('text=Historial')).toBeVisible()
        await expect(page.locator('ol[aria-label="Historial de estados de la cotización"]')).toContainText('Borrador')
    })

    test('the PDF action opens the PDF endpoint for this quotation', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }

        await gotoDetail(page, id)

        await expect(page.locator('[data-testid="btn-pdf"]')).toBeVisible()

        // Stub window.open so we can deterministically capture the target URL
        // (the noopener popup makes asserting on the real tab's url() racy).
        await page.evaluate(() => {
            ;(window as unknown as { __lastOpenUrl: string | null }).__lastOpenUrl = null
            window.open = (url?: string | URL) => {
                ;(window as unknown as { __lastOpenUrl: string | null }).__lastOpenUrl =
                    url ? String(url) : null
                return null
            }
        })

        await page.click('[data-testid="btn-pdf"]')

        const openedUrl = await page.evaluate(
            () => (window as unknown as { __lastOpenUrl: string | null }).__lastOpenUrl,
        )
        expect(openedUrl).toContain(`/api/v1/quotations/${id}/pdf`)
    })
})

test.describe('Quotation detail — transitions (S7-E8)', () => {

    test('send: a draft becomes Enviada', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }

        await gotoDetail(page, id)

        await page.click('[data-testid="btn-send"]')

        await expect(page.locator('text=Cotización enviada')).toBeVisible({ timeout: 8_000 })
        await expect(page.locator('[data-testid="quotation-status-badge"]')).toContainText('Enviada')
        // Send button is gone once sent
        await expect(page.locator('[data-testid="btn-send"]')).toHaveCount(0)
    })

    test('accept (without convert): a sent quotation becomes Aceptada', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }
        // Move it to sent via API so the detail starts from a realistic state.
        await apiTransition(page, id, 'send')

        await gotoDetail(page, id)

        // Open the accept confirm, leave the convert checkbox unchecked, confirm.
        await page.click('[data-testid="btn-accept"]')
        await expect(page.locator('[data-testid="accept-confirm"]')).toBeVisible()
        await page.click('[data-testid="btn-accept-confirm"]')

        await expect(page.locator('text=Cotización aceptada')).toBeVisible({ timeout: 8_000 })
        await expect(page.locator('[data-testid="quotation-status-badge"]')).toContainText('Aceptada')
        // No converted banner since we did not convert
        await expect(page.locator('[data-testid="converted-banner"]')).toHaveCount(0)
    })

    test('accept + convert: creates an order and navigates to it', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }

        await gotoDetail(page, id)

        await page.click('[data-testid="btn-accept"]')
        await expect(page.locator('[data-testid="accept-confirm"]')).toBeVisible()
        // Tick the convert checkbox
        await page.check('[data-testid="convert-checkbox"]')
        await page.click('[data-testid="btn-accept-confirm"]')

        // We navigate to the freshly created order detail.
        await page.waitForURL('**/admin/orders/**', { timeout: 12_000 })
        expect(page.url()).toContain('/admin/orders/')
    })

    test('reject: a sent quotation becomes Rechazada', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }
        await apiTransition(page, id, 'send')

        await gotoDetail(page, id)

        await page.click('[data-testid="btn-reject"]')
        await expect(page.locator('[data-testid="reject-confirm"]')).toBeVisible()
        await page.click('[data-testid="btn-reject-confirm"]')

        await expect(page.locator('text=Cotización rechazada')).toBeVisible({ timeout: 8_000 })
        await expect(page.locator('[data-testid="quotation-status-badge"]')).toContainText('Rechazada')
    })

    test('terminal state exposes no transition actions', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }
        // Reject via API so the quotation reaches a terminal state.
        await apiTransition(page, id, 'reject')

        await gotoDetail(page, id)

        await expect(page.locator('[data-testid="btn-send"]')).toHaveCount(0)
        await expect(page.locator('[data-testid="btn-accept"]')).toHaveCount(0)
        await expect(page.locator('[data-testid="btn-reject"]')).toHaveCount(0)
        await expect(page.locator('text=No hay acciones disponibles')).toBeVisible()
    })
})

test.describe('Quotation detail — edit draft (S7-E8)', () => {

    test('the edit action opens the builder in edit mode for a draft', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }

        await gotoDetail(page, id)

        await expect(page.locator('[data-testid="btn-edit"]')).toBeVisible()
        await page.click('[data-testid="btn-edit"]')

        await expect(
            page.locator('[data-testid="quotation-builder-slideover"]'),
        ).toBeVisible({ timeout: 5_000 })
        await expect(
            page.locator('[data-testid="btn-submit-quotation"]'),
        ).toContainText('Guardar cambios')
    })

    test('a sent quotation does not expose the edit action', async ({ page }) => {
        await login(page)
        const id = await createDraft(page)
        if (id === null) {
            test.skip()
            return
        }
        await apiTransition(page, id, 'send')

        await gotoDetail(page, id)

        await expect(page.locator('[data-testid="btn-edit"]')).toHaveCount(0)
    })
})
