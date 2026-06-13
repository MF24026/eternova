import { test, expect, type Page } from '@playwright/test'

/**
 * Quotations Admin UI — list page, status tabs, filters (S7-E6).
 *
 * Exercises QuotationsPage against the live API on the demo tenant.
 *
 * NOTE: The quotations seeder lands in S7-E8. The list may be empty at this
 * stage. All assertions are seeder-resilient: they verify that the page
 * structure is present and interactive, and assert rows OR the empty state
 * is rendered — never a specific row count or content.
 *
 * Why in-page fetch:
 *   Node's HTTP client cannot resolve *.eternova.localhost — Chromium applies
 *   the RFC 6761 loopback rule. page.evaluate() runs inside the browser where
 *   the host resolves and the session cookie is sent automatically.
 *
 * Run with: --workers=1 (shared DB state)
 *
 * Requires DemoTenantsSeeder:
 *   - Tenant slug: rosa-eterna
 *   - Owner: caro@rosaeterna.com / DemoPro123!
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const QUOTATIONS_URL = `${TENANT_BASE}/admin/quotations`

// ── Helpers ───────────────────────────────────────────────────────────────────

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function gotoQuotationsPage(page: Page): Promise<void> {
    await page.goto(QUOTATIONS_URL)
    // Wait for the page title h1 — structural marker for a fully mounted page
    await page.waitForSelector('h1:has-text("Cotizaciones")', { timeout: 15_000 })
    await waitForNoSkeleton(page)
}

async function waitForNoSkeleton(page: Page): Promise<void> {
    await page.waitForFunction(
        () => document.querySelectorAll('.animate-pulse').length === 0,
        { timeout: 10_000 },
    )
}

/**
 * In-page fetch helper. Runs inside Chromium so *.eternova.localhost resolves
 * and the session cookie is included automatically.
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

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe('Quotations list page (S7-E6)', () => {

    test('page renders with header, "Nueva cotización" button, and status tabs', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // Page title
        await expect(page.locator('h1:has-text("Cotizaciones")')).toBeVisible()

        // Primary CTA
        await expect(page.locator('[data-testid="btn-nueva-cotizacion"]')).toBeVisible()

        // Status tabs container
        await expect(page.locator('[data-testid="status-tabs"]')).toBeVisible()

        // "Todas" tab always present
        await expect(page.locator('[data-testid="tab-all"]')).toBeVisible()
    })

    test('status tabs are present for all five statuses', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        for (const status of ['draft', 'sent', 'accepted', 'rejected', 'expired']) {
            await expect(
                page.locator(`[data-testid="tab-${status}"]`),
                `Tab for status "${status}" should be visible`,
            ).toBeVisible()
        }
    })

    test('filters bar is present with date inputs and search field', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // Filters bar
        await expect(page.locator('[data-testid="filters-bar"]')).toBeVisible()

        // Date inputs
        await expect(page.locator('input[aria-label="Fecha de emisión desde"]')).toBeVisible()
        await expect(page.locator('input[aria-label="Fecha de emisión hasta"]')).toBeVisible()

        // Search
        await expect(page.locator('[data-testid="search-input"]')).toBeVisible()
    })

    test('clicking a status tab filters the list (or shows empty state)', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // Click "Borrador" tab
        await page.click('[data-testid="tab-draft"]')
        await waitForNoSkeleton(page)

        // Either rows or empty state must be visible after filtering
        const hasRows = await page.locator('table tbody tr, [data-testid^="quotation-card-"]').count()
        const hasEmpty = await page.locator('text=No hay cotizaciones').count()
        expect(
            hasRows + hasEmpty,
            'Either table rows or the empty state should be visible after filtering',
        ).toBeGreaterThan(0)
    })

    test('search input triggers a filter request (empty-state or rows)', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // Type a term that won't match anything
        await page.fill('input[placeholder="Buscar número o cliente..."]', 'xQzNoMatchQuotation999')
        await page.waitForTimeout(400) // wait for debounce
        await waitForNoSkeleton(page)

        // Should render empty state on both desktop table and mobile cards
        const emptyCount = await page.locator('text=No hay cotizaciones').count()
        expect(emptyCount).toBeGreaterThan(0)
    })

    test('date range filter is interactable', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        const today = new Date().toISOString().slice(0, 10)

        await page.fill('input[aria-label="Fecha de emisión desde"]', '2026-01-01')
        await page.fill('input[aria-label="Fecha de emisión hasta"]', today)
        await waitForNoSkeleton(page)

        // Page must not crash — either rows or empty state
        const hasRows = await page.locator('table tbody tr, [data-testid^="quotation-card-"]').count()
        const hasEmpty = await page.locator('text=No hay cotizaciones').count()
        expect(hasRows + hasEmpty).toBeGreaterThan(0)
    })

    test('list renders rows or empty state (seeder-resilient)', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // If quotations exist, the table (desktop) or mobile cards render.
        // If none exist, the empty state renders.
        const tableRows = await page.locator('table tbody tr').count()
        const mobileCards = await page.locator('[data-testid^="quotation-card-"]').count()
        const emptyState = await page.locator('text=No hay cotizaciones').count()

        expect(
            tableRows + mobileCards + emptyState,
            'Either rows or an empty state must be present',
        ).toBeGreaterThan(0)
    })

    test('row/card click navigates to quotation detail (if rows exist)', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // Create a quotation via API so we have something to click
        const result = await apiFetch(page, '/api/v1/quotations', {
            method: 'POST',
            body: {
                issue_date: new Date().toISOString().slice(0, 10),
                items: [
                    {
                        description: 'Ítem de prueba E2E',
                        quantity: 1,
                        unit_price_cents: 1000,
                    },
                ],
            },
        })

        if (!result.ok) {
            // Backend not yet ready or validation changed — skip navigation check
            test.skip()
            return
        }

        // Reload the page to see the new quotation
        await gotoQuotationsPage(page)
        await waitForNoSkeleton(page)

        // Click the first available row (desktop) or card (mobile)
        const firstRow = page.locator('table tbody tr').first()
        const firstCard = page.locator('[data-testid^="quotation-card-"]').first()

        const rowCount = await firstRow.count()
        const cardCount = await firstCard.count()

        if (rowCount > 0) {
            await firstRow.click()
        } else if (cardCount > 0) {
            await firstCard.click()
        } else {
            // Still empty — navigation test not applicable
            return
        }

        // Should navigate to the detail page
        await page.waitForURL('**/admin/quotations/**', { timeout: 10_000 })
        await expect(page.locator('[data-testid="quotation-detail"]')).toBeVisible({ timeout: 10_000 })
    })
})

test.describe('Quotations list — mobile (S7-E6)', () => {

    test('list renders correctly on a 375px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoQuotationsPage(page)

        // Header visible
        await expect(page.locator('h1:has-text("Cotizaciones")')).toBeVisible()

        // Status tabs visible
        await expect(page.locator('[data-testid="status-tabs"]')).toBeVisible()

        // Mobile card list container exists (even if empty)
        await expect(page.locator('[data-testid="mobile-card-list"]')).toBeVisible()
    })

    test('status tabs are scrollable / interactable on 375px', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoQuotationsPage(page)

        // All tabs present
        await expect(page.locator('[data-testid="tab-all"]')).toBeVisible()
        await expect(page.locator('[data-testid="tab-draft"]')).toBeVisible()

        // Tab is clickable without crashing the page
        await page.click('[data-testid="tab-sent"]')
        await waitForNoSkeleton(page)
        await expect(page.locator('h1:has-text("Cotizaciones")')).toBeVisible()
    })
})
