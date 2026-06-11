import { test, expect, type Page } from '@playwright/test'

/**
 * Order detail page (S4-E6) — Playwright E2E against the seeded demo tenant.
 *
 * Requires DemoTenantsSeeder + S4-E8 orders seeder (caro@rosaeterna.com on
 * tenant rosa-eterna — ~15 orders across all statuses with timeline entries).
 *
 * Why in-page fetch (not page.request):
 *   Node's HTTP client cannot resolve *.eternova.localhost — only Chromium
 *   applies the RFC 6761 loopback rule. page.evaluate() runs inside the browser
 *   so the host resolves and the session cookie is sent automatically.
 *
 * Mutation policy:
 *   This spec advances ONE order by one step. The S4-E8 seeder is idempotent
 *   on re-seed; advancing a single order is acceptable for E2E coverage.
 *   Cancellation is NOT tested on an otherwise-testable order to avoid removing
 *   it from the pool for other specs.
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
 * In-page fetch — runs inside Chromium so *.eternova.localhost resolves and
 * the Sanctum session cookie is sent automatically.
 */
async function apiFetch<T = unknown>(
    page: Page,
    path: string,
    options: { method?: string; body?: unknown } = {},
): Promise<{ status: number; ok: boolean; body: T }> {
    return page.evaluate(
        async ([p, opts]) => {
            const r = await fetch(p as string, {
                method: (opts as { method?: string }).method ?? 'GET',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
                body: (opts as { body?: unknown }).body
                    ? JSON.stringify((opts as { body?: unknown }).body)
                    : undefined,
            })
            return { status: r.status, ok: r.ok, body: await r.json() }
        },
        [path, options] as [string, { method?: string; body?: unknown }],
    ) as Promise<{ status: number; ok: boolean; body: T }>
}

/**
 * Finds an order that is NOT in a terminal state (not delivered or cancelled)
 * and that has at least one allowed transition, so we can exercise the advance-
 * status button. Returns null if no such order exists in the seeded data.
 */
async function findTransitionableOrder(
    page: Page,
): Promise<{ id: string; status: string; order_number: string } | null> {
    const result = await apiFetch<{
        data: Array<{ id: string; status: string; order_number: string; allowed_transitions: string[] }>
    }>(page, '/api/v1/orders?per_page=50')

    if (!result.ok) return null

    const transitionable = result.body.data.find(
        (o) =>
            o.allowed_transitions.length > 0 &&
            o.status !== 'delivered' &&
            o.status !== 'cancelled',
    )
    return transitionable ?? null
}

/** Navigate to a detail page and wait for it to fully load. */
async function gotoDetailPage(page: Page, orderId: string): Promise<void> {
    await page.goto(`${TENANT_BASE}/admin/orders/${orderId}`)
    // Wait until the serif order_number heading is visible — the page is loaded.
    await page.waitForSelector('h1.serif, h1[class*="serif"]', { timeout: 15_000 })
    // Wait until any spinner is gone.
    await page.waitForFunction(
        () => document.querySelectorAll('[role="status"][aria-label="Cargando"]').length === 0,
        { timeout: 15_000 },
    )
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Order detail page (S4-E6)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page)
    })

    // ── Page structure ───────────────────────────────────────────────────────

    test('renders order_number as page heading', async ({ page }) => {
        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        await gotoDetailPage(page, order.id)

        await expect(page.getByRole('heading', { name: order.order_number })).toBeVisible()
        // Document title updated
        expect(await page.title()).toContain(order.order_number)
    })

    test('items section renders product names and line totals', async ({ page }) => {
        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        // Fetch the full detail to know what items to expect
        const detail = await apiFetch<{ data: { items: Array<{ product_snapshot: { name: string | null } }> } }>(
            page,
            `/api/v1/orders/${order.id}`,
        )
        if (!detail.ok || detail.body.data.items.length === 0) {
            test.skip()
            return
        }

        await gotoDetailPage(page, order.id)

        // At least the first item's product name is visible
        const firstName = detail.body.data.items[0].product_snapshot.name
        if (firstName) {
            await expect(page.getByText(firstName, { exact: false })).toBeVisible()
        }

        // "Total" label appears in the totals section
        await expect(page.getByText('Total', { exact: true }).first()).toBeVisible()
    })

    test('status badge matches current order status', async ({ page }) => {
        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        await gotoDetailPage(page, order.id)

        const STATUS_LABELS: Record<string, string> = {
            pending: 'Pendiente',
            preparing: 'Preparando',
            ready: 'Listo',
            dispatched: 'Despachado',
            delivered: 'Entregado',
            cancelled: 'Cancelado',
        }
        const expectedLabel = STATUS_LABELS[order.status]
        if (!expectedLabel) {
            test.skip()
            return
        }

        // The status badge in the header area (first occurrence)
        const badge = page.getByText(expectedLabel).first()
        await expect(badge).toBeVisible()
    })

    test('timeline card shows at least one history entry', async ({ page }) => {
        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        await gotoDetailPage(page, order.id)

        // The timeline heading
        await expect(page.getByRole('heading', { name: 'Historial', exact: true })).toBeVisible()

        // At least one history entry — identified by the ordered list
        const timeline = page.getByRole('list', { name: /historial de estados/i })
        await expect(timeline).toBeVisible()
        const firstEntry = timeline.locator('li').first()
        await expect(firstEntry).toBeVisible()
    })

    test('assignee selector renders with team member options', async ({ page }) => {
        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        // Fetch team to know the expected count
        const teamResult = await apiFetch<{ data: Array<{ id: number; name: string }> }>(
            page,
            '/api/v1/team',
        )

        await gotoDetailPage(page, order.id)

        const select = page.getByRole('combobox', { name: /asignar pedido/i })
        await expect(select).toBeVisible()

        if (teamResult.ok && teamResult.body.data.length > 0) {
            // Wait until Vue's loadTeam() has resolved and populated the options.
            // <option> elements are not "visible" in Playwright's sense (they live
            // inside a native dropdown), so we wait for the count to exceed 1.
            await expect(async () => {
                const count = await select.locator('option').count()
                expect(count).toBeGreaterThanOrEqual(2)
            }).toPass({ timeout: 10_000 })
        }
    })

    // ── Advance status ───────────────────────────────────────────────────────

    test('advance status button updates the status badge and adds a timeline entry', async ({ page }) => {
        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        // Fetch full detail to know the first allowed transition
        const detail = await apiFetch<{
            data: { allowed_transitions: string[]; status_history: Array<{ id: number }> }
        }>(page, `/api/v1/orders/${order.id}`)

        if (!detail.ok || detail.body.data.allowed_transitions.length === 0) {
            test.skip()
            return
        }

        const firstTransition = detail.body.data.allowed_transitions[0]
        const historyCountBefore = detail.body.data.status_history.length

        const STATUS_LABELS: Record<string, string> = {
            pending: 'Pendiente',
            preparing: 'Preparando',
            ready: 'Listo',
            dispatched: 'Despachado',
            delivered: 'Entregado',
            cancelled: 'Cancelado',
        }
        const nextLabel = STATUS_LABELS[firstTransition]

        await gotoDetailPage(page, order.id)

        // Click the transition button ("Marcar como <Label>")
        const transitionBtn = page.getByRole('button', { name: new RegExp(`Marcar como ${nextLabel}`, 'i') })
        await expect(transitionBtn).toBeVisible()
        await transitionBtn.click()

        // Wait for the loading state to clear
        await page.waitForFunction(
            () => document.querySelectorAll('[role="status"][aria-label="Cargando"]').length === 0,
            { timeout: 15_000 },
        )

        // Status badge in the header should now show the new status
        await expect(page.getByText(nextLabel).first()).toBeVisible()

        // Timeline should have gained an entry
        const timeline = page.getByRole('list', { name: /historial de estados/i })
        const newEntryCount = await timeline.locator('li').count()
        expect(newEntryCount).toBeGreaterThanOrEqual(historyCountBefore + 1)
    })

    // ── Assign ───────────────────────────────────────────────────────────────

    test('changing the assignee select persists via API', async ({ page }) => {
        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        const teamResult = await apiFetch<{ data: Array<{ id: number; name: string }> }>(
            page,
            '/api/v1/team',
        )
        if (!teamResult.ok || teamResult.body.data.length === 0) {
            test.skip()
            return
        }

        const targetMember = teamResult.body.data[0]

        await gotoDetailPage(page, order.id)

        const select = page.getByRole('combobox', { name: /asignar pedido/i })
        await select.selectOption(String(targetMember.id))

        // Wait for the assign request to complete (spinner disappears from inside select area)
        await page.waitForFunction(
            () => document.querySelectorAll('[role="status"][aria-label="Cargando"]').length === 0,
            { timeout: 15_000 },
        )

        // Verify via re-fetch that the order now has the new assignee
        const updated = await apiFetch<{ data: { assignee: { id: number } | null } }>(
            page,
            `/api/v1/orders/${order.id}`,
        )
        expect(updated.ok).toBeTruthy()
        expect(updated.body.data.assignee?.id).toBe(targetMember.id)
    })

    // ── Not-found ────────────────────────────────────────────────────────────

    test('shows friendly not-found state for a non-existent order id', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/admin/orders/01JXXXXXXXXXXXXXXXXXXXXXXXXX`)
        // Wait for Vue to mount
        await page.waitForFunction(
            () => {
                const app = document.querySelector('#app')
                return app !== null && app.children.length > 0
            },
            { timeout: 15_000 },
        )

        await expect(page.getByRole('heading', { name: /no encontrado/i })).toBeVisible({
            timeout: 10_000,
        })
        await expect(page.getByRole('button', { name: /volver a pedidos/i })).toBeVisible()
    })

    // ── Responsive: mobile viewport ──────────────────────────────────────────

    test('page renders stacked at mobile width (375px)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })

        const order = await findTransitionableOrder(page)
        if (!order) {
            test.skip()
            return
        }

        await gotoDetailPage(page, order.id)

        // Heading visible on mobile
        await expect(page.getByRole('heading', { name: order.order_number })).toBeVisible()

        // Back link visible
        await expect(page.getByText(/volver a pedidos/i).first()).toBeVisible()

        // Actions card heading visible (confirms right column stacked below)
        await expect(page.getByRole('heading', { name: 'Acciones', exact: true })).toBeVisible()
    })
})
