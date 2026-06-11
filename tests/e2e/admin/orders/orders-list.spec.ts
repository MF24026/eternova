import { test, expect, type Page } from '@playwright/test'

/**
 * Orders list page (S4-E5) — Playwright E2E against the seeded demo tenant.
 *
 * Requires DemoTenantsSeeder (owner caro@rosaeterna.com on tenant rosa-eterna).
 * Orders may be sparse (E8 adds richer seed data). All assertions are written
 * to be resilient: if zero orders exist, we verify structure (tabs, empty state,
 * counts all-zero) rather than failing on concrete row values.
 *
 * Why in-page fetch (not page.request):
 *   Node's HTTP client cannot resolve *.eternova.localhost — only Chromium
 *   applies the RFC 6761 loopback rule. page.evaluate() runs inside the browser
 *   so the host resolves and the session cookie is sent automatically.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const ORDERS_URL = `${TENANT_BASE}/admin/orders`

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

/** Wait for the orders page component to finish its initial load. */
async function gotoOrdersPage(page: Page): Promise<void> {
    await page.goto(ORDERS_URL)
    // Wait until Vue has mounted the page (tabs are the first structural element).
    await page.waitForSelector('[role="tablist"]', { timeout: 15_000 })
    // Wait until the loading skeleton has gone away (table renders or empty state appears).
    await page.waitForFunction(
        () => {
            // The skeleton rows have an aria-busy="true" or we can detect that
            // the animate-pulse cells are gone. Simplest: wait until no .animate-pulse exists.
            return document.querySelectorAll('.animate-pulse').length === 0
        },
        { timeout: 15_000 },
    )
}

/**
 * In-page fetch so the session cookie is sent and *.eternova.localhost resolves.
 */
async function apiFetch(
    page: Page,
    path: string,
): Promise<{ status: number; ok: boolean; body: unknown }> {
    return page.evaluate(async (p) => {
        const r = await fetch(p, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        return { status: r.status, ok: r.ok, body: await r.json() }
    }, path)
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Orders list page (S4-E5)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page)
    })

    // ── Page structure ───────────────────────────────────────────────────────

    test('page renders the heading and status tab rail', async ({ page }) => {
        await gotoOrdersPage(page)

        // Serif headline
        await expect(page.getByRole('heading', { name: 'Pedidos', exact: true })).toBeVisible()

        // Tab rail
        const tablist = page.getByRole('tablist', { name: /filtrar por estado/i })
        await expect(tablist).toBeVisible()

        // "Todos" is the first tab and selected by default
        const todosTab = tablist.getByRole('tab', { name: /todos/i })
        await expect(todosTab).toBeVisible()
        await expect(todosTab).toHaveAttribute('aria-selected', 'true')

        // All six status tabs are present
        for (const label of ['Pendiente', 'Preparando', 'Listo', 'Despachado', 'Entregado', 'Cancelado']) {
            await expect(tablist.getByRole('tab', { name: new RegExp(label, 'i') })).toBeVisible()
        }
    })

    test('tab counts match the API status_counts', async ({ page }) => {
        await gotoOrdersPage(page)

        const apiResult = await apiFetch(page, '/api/v1/orders')
        expect(apiResult.ok).toBeTruthy()

        const body = apiResult.body as Record<string, unknown>
        const counts = body['status_counts'] as Record<string, number>

        // Verify the "Todos" tab count matches the sum of all statuses
        const total = Object.values(counts).reduce((s, n) => s + n, 0)
        const todosTab = page.getByRole('tab', { name: /todos/i })
        await expect(todosTab).toContainText(String(total))
    })

    // ── Table structure ──────────────────────────────────────────────────────

    test('table renders or shows the empty state — never blank', async ({ page }) => {
        await gotoOrdersPage(page)

        const table = page.locator('table')
        const emptyState = page.getByRole('heading', { name: 'Sin pedidos' })

        // One of the two must be visible
        const hasTable = await table.isVisible().catch(() => false)
        const hasEmpty = await emptyState.isVisible().catch(() => false)

        expect(
            hasTable || hasEmpty,
            'Expected either a table or empty state to be visible',
        ).toBeTruthy()
    })

    test('table has the expected column headers when orders exist', async ({ page }) => {
        // Only run the column check when the API has at least one order
        const apiResult = await apiFetch(page, '/api/v1/orders')
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as unknown[]
        if (data.length === 0) {
            test.skip()
            return
        }

        await gotoOrdersPage(page)

        const thead = page.locator('thead')
        for (const header of ['Pedido', 'Cliente', 'Sucursal', 'Fecha', 'Origen', 'Total', 'Estado']) {
            await expect(thead.getByText(new RegExp(header, 'i'))).toBeVisible()
        }
    })

    // ── Tab switching ────────────────────────────────────────────────────────

    test('switching to Pendiente tab refetches and shows only pending orders', async ({ page }) => {
        await gotoOrdersPage(page)

        const tablist = page.getByRole('tablist')
        const pendingTab = tablist.getByRole('tab', { name: /pendiente/i })
        await pendingTab.click()

        // The tab becomes active
        await expect(pendingTab).toHaveAttribute('aria-selected', 'true')

        // Wait for the data to reload
        await page.waitForFunction(
            () => document.querySelectorAll('.animate-pulse').length === 0,
            { timeout: 10_000 },
        )

        // If rows are shown, they should NOT contain 'Preparando', 'Listo', etc. status pills
        // We check by querying visible status badges that are NOT 'Pendiente'
        const wrongStatusBadges = page.locator('tbody td span[class*="bg-info"]')
        // info = preparing/dispatched; if the filter works, there should be none
        // (but we can only assert 0 if the API actually returned results for pending)
        const apiResult = await apiFetch(page, '/api/v1/orders?status=pending')
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as unknown[]

        if (data.length === 0) {
            // Empty state should be shown
            await expect(page.getByRole('heading', { name: 'Sin pedidos' })).toBeVisible()
        } else {
            // Rows should be shown — no "Preparando" badges should appear
            const preparingBadges = page.getByText('Preparando')
            const count = await preparingBadges.count()
            expect(count, 'No "Preparando" badges should appear on the Pendiente tab').toBe(0)
        }
    })

    test('switching back to Todos tab shows all orders again', async ({ page }) => {
        await gotoOrdersPage(page)

        const tablist = page.getByRole('tablist')

        // Click Pendiente
        await tablist.getByRole('tab', { name: /pendiente/i }).click()
        await page.waitForFunction(
            () => document.querySelectorAll('.animate-pulse').length === 0,
            { timeout: 10_000 },
        )

        // Click Todos
        const todosTab = tablist.getByRole('tab', { name: /todos/i })
        await todosTab.click()
        await expect(todosTab).toHaveAttribute('aria-selected', 'true')

        await page.waitForFunction(
            () => document.querySelectorAll('.animate-pulse').length === 0,
            { timeout: 10_000 },
        )

        // Verify the API total matches what we expect
        const apiResult = await apiFetch(page, '/api/v1/orders')
        const body = apiResult.body as Record<string, unknown>
        const counts = body['status_counts'] as Record<string, number>
        const total = Object.values(counts).reduce((s, n) => s + n, 0)
        await expect(todosTab).toContainText(String(total))
    })

    // ── Search filter ────────────────────────────────────────────────────────

    test('search input filters by order_number (debounced)', async ({ page }) => {
        // Only meaningful if there are orders in the tenant
        const apiResult = await apiFetch(page, '/api/v1/orders')
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as Array<Record<string, unknown>>

        if (data.length === 0) {
            test.skip()
            return
        }

        await gotoOrdersPage(page)

        // Take the first order's number and search for a prefix
        const firstOrderNumber = String(data[0]['order_number'] ?? '')
        if (!firstOrderNumber) {
            test.skip()
            return
        }

        const searchInput = page.getByPlaceholder(/buscar número de pedido/i)
        await searchInput.fill(firstOrderNumber)

        // Wait for the debounce (300ms) + network round-trip
        await page.waitForTimeout(400)
        await page.waitForFunction(
            () => document.querySelectorAll('.animate-pulse').length === 0,
            { timeout: 10_000 },
        )

        // At least one row with that order number should be visible
        await expect(page.getByText(firstOrderNumber)).toBeVisible()
    })

    test('search with no matches shows the empty state', async ({ page }) => {
        await gotoOrdersPage(page)

        const searchInput = page.getByPlaceholder(/buscar número de pedido/i)
        await searchInput.fill('XXXXXXXXXXX-THIS-CANNOT-EXIST')

        await page.waitForTimeout(400)
        await page.waitForFunction(
            () => document.querySelectorAll('.animate-pulse').length === 0,
            { timeout: 10_000 },
        )

        await expect(page.getByRole('heading', { name: 'Sin pedidos' })).toBeVisible()
    })

    // ── Row click → navigation ───────────────────────────────────────────────

    test('clicking a row navigates to the order detail page', async ({ page }) => {
        // Only runnable if there is at least one order
        const apiResult = await apiFetch(page, '/api/v1/orders')
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as Array<Record<string, unknown>>

        if (data.length === 0) {
            test.skip()
            return
        }

        await gotoOrdersPage(page)

        const firstOrderId = String(data[0]['id'] ?? '')

        // Click the first tbody row
        const firstRow = page.locator('tbody tr').first()
        await firstRow.click()

        // URL should change to /admin/orders/{id}
        await page.waitForURL(`**/admin/orders/${firstOrderId}`, { timeout: 10_000 })
        expect(page.url()).toContain(`/admin/orders/${firstOrderId}`)
    })

    // ── Responsive: mobile viewport ──────────────────────────────────────────

    test('page structure renders at mobile width (375px)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await gotoOrdersPage(page)

        // Tabs should still be visible (horizontally scrollable)
        await expect(page.getByRole('tablist')).toBeVisible()

        // Heading visible
        await expect(page.getByRole('heading', { name: 'Pedidos', exact: true })).toBeVisible()
    })
})
