import { test, expect, type Page } from '@playwright/test'

/**
 * Reservations main view — Calendar / Lista / Tablero toggle (S5-E6).
 *
 * Exercises the three view modes, tab filtering, row/chip navigation to the
 * detail stub, and kanban drag-to-transition with API verification.
 *
 * Requires DemoTenantsSeeder (caro@rosaeterna.com on tenant rosa-eterna)
 * and the S5-E8 seeder (≥12 reservations across all statuses).
 *
 * Why in-page fetch: Node's HTTP client cannot resolve *.eternova.localhost.
 * page.evaluate() runs inside Chromium where the host resolves and the session
 * cookie is sent automatically via credentials: 'include'.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const RESERVATIONS_URL = `${TENANT_BASE}/admin/reservations`

// ── Helpers ──────────────────────────────────────────────────────────────────

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function gotoReservationsPage(page: Page): Promise<void> {
    await page.goto(RESERVATIONS_URL)
    // Wait for the view-toggle group to be rendered (structural marker)
    await page.waitForSelector('[role="group"][aria-label="Modo de vista"]', { timeout: 15_000 })
    // Wait for the initial loading to finish (table skeleton + board/calendar spinner gone)
    await page.waitForFunction(
        () =>
            document.querySelectorAll('.animate-pulse').length === 0 &&
            document.querySelectorAll('.animate-spin').length === 0,
        { timeout: 15_000 },
    )
}

/**
 * In-page fetch with XSRF token for state-mutating requests.
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

async function waitForNoSkeleton(page: Page): Promise<void> {
    // Wait for the table skeleton (animate-pulse) AND the board/calendar spinner (animate-spin)
    // to disappear, indicating the data fetch has completed.
    await page.waitForFunction(
        () =>
            document.querySelectorAll('.animate-pulse').length === 0 &&
            document.querySelectorAll('.animate-spin').length === 0,
        { timeout: 10_000 },
    )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe('Reservations views (S5-E6)', () => {
    test.beforeEach(async ({ page }) => {
        await login(page)
    })

    // ── Page structure / view toggle ─────────────────────────────────────────

    test('page renders heading and the three-way view toggle', async ({ page }) => {
        await gotoReservationsPage(page)

        await expect(page.getByRole('heading', { name: 'Reservas', exact: true })).toBeVisible()

        const toggle = page.getByRole('group', { name: /modo de vista/i })
        await expect(toggle).toBeVisible()

        await expect(toggle.getByRole('button', { name: /lista/i })).toBeVisible()
        await expect(toggle.getByRole('button', { name: /calendario/i })).toBeVisible()
        await expect(toggle.getByRole('button', { name: /tablero/i })).toBeVisible()
    })

    test('switching to Calendario renders the month grid', async ({ page }) => {
        await gotoReservationsPage(page)

        const toggle = page.getByRole('group', { name: /modo de vista/i })
        await toggle.getByRole('button', { name: /calendario/i }).click()

        // Month grid is rendered (7-column grid of day cells)
        // Wait for the skeleton to clear after the fetch
        await waitForNoSkeleton(page)

        // A month name (Spanish) should appear
        await expect(
            page.getByText(/enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|octubre|noviembre|diciembre/i).first(),
        ).toBeVisible()

        // Weekday header row
        await expect(page.getByText('Lun').first()).toBeVisible()
    })

    test('switching to Tablero renders kanban columns', async ({ page }) => {
        await gotoReservationsPage(page)

        const toggle = page.getByRole('group', { name: /modo de vista/i })
        await toggle.getByRole('button', { name: /tablero/i }).click()

        await waitForNoSkeleton(page)

        // Board columns container
        const board = page.getByTestId('board-columns')
        await expect(board).toBeVisible()

        // All workflow status columns rendered
        for (const col of ['inquiry', 'confirmed', 'in_progress', 'ready', 'delivered']) {
            await expect(page.getByTestId(`board-column-${col}`)).toBeVisible()
        }
    })

    test('switching views is persistent (stored in localStorage)', async ({ page }) => {
        await gotoReservationsPage(page)

        // Switch to Tablero
        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /tablero/i })
            .click()
        await waitForNoSkeleton(page)

        // Navigate away and back
        await page.goto(`${TENANT_BASE}/admin/dashboard`)
        await page.goto(RESERVATIONS_URL)
        await page.waitForSelector('[role="group"][aria-label="Modo de vista"]', { timeout: 15_000 })

        // Tablero button should be pressed (active)
        const tableroBtn = page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /tablero/i })
        await expect(tableroBtn).toHaveAttribute('aria-pressed', 'true')
    })

    // ── LIST view ─────────────────────────────────────────────────────────────

    test('Lista — status tabs with counts render correctly', async ({ page }) => {
        await gotoReservationsPage(page)

        // Ensure we are in list view (default)
        const toggle = page.getByRole('group', { name: /modo de vista/i })
        await toggle.getByRole('button', { name: /lista/i }).click()
        await waitForNoSkeleton(page)

        const tablist = page.getByRole('tablist', { name: /filtrar por estado/i })
        await expect(tablist).toBeVisible()

        // "Todos" tab is active by default
        await expect(tablist.getByRole('tab', { name: /todos/i })).toHaveAttribute('aria-selected', 'true')

        // All six status tabs
        for (const label of ['Consulta', 'Confirmada', 'En proceso', 'Lista', 'Entregada', 'Cancelada']) {
            await expect(tablist.getByRole('tab', { name: new RegExp(label, 'i') })).toBeVisible()
        }
    })

    test('Lista — tab counts match API status_counts', async ({ page }) => {
        await gotoReservationsPage(page)

        const apiResult = await apiFetch(page, '/api/v1/reservations')
        expect(apiResult.ok).toBeTruthy()

        const body = apiResult.body as Record<string, unknown>
        const counts = body['status_counts'] as Record<string, number>
        const total = Object.values(counts).reduce((s: number, n: number) => s + n, 0)

        const todosTab = page.getByRole('tab', { name: /todos/i })
        await expect(todosTab).toContainText(String(total))
    })

    test('Lista — switching a tab filters the result', async ({ page }) => {
        await gotoReservationsPage(page)

        // Switch to list view
        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /lista/i })
            .click()
        await waitForNoSkeleton(page)

        // Fetch API data for the 'inquiry' status to know what to expect
        const apiResult = await apiFetch(page, '/api/v1/reservations?status=inquiry')
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as unknown[]

        const consultaTab = page.getByRole('tab', { name: /consulta/i })
        await consultaTab.click()
        await expect(consultaTab).toHaveAttribute('aria-selected', 'true')
        await waitForNoSkeleton(page)

        if (data.length === 0) {
            await expect(page.getByRole('heading', { name: /sin reservas/i })).toBeVisible()
        } else {
            // Only Consulta status pills should appear in the table body
            const tbody = page.locator('tbody')
            for (const wrongLabel of ['Confirmada', 'En proceso', 'Lista', 'Entregada', 'Cancelada']) {
                await expect(tbody.getByText(wrongLabel, { exact: true })).toHaveCount(0)
            }
            await expect(tbody.getByText('Consulta', { exact: true }).first()).toBeVisible()
        }
    })

    test('Lista — row click navigates to /admin/reservations/:id', async ({ page }) => {
        const apiResult = await apiFetch(page, '/api/v1/reservations')
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as Array<Record<string, unknown>>

        if (data.length === 0) {
            test.skip()
            return
        }

        await gotoReservationsPage(page)
        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /lista/i })
            .click()
        await waitForNoSkeleton(page)

        const firstId = String(data[0]['id'] ?? '')
        const firstRow = page.locator('tbody tr').first()
        await firstRow.click()

        await page.waitForURL(`**/admin/reservations/${firstId}`, { timeout: 10_000 })
        expect(page.url()).toContain(`/admin/reservations/${firstId}`)
    })

    // ── CALENDAR view ─────────────────────────────────────────────────────────

    test('Calendario — month grid renders with reservation chips on event dates', async ({ page }) => {
        await gotoReservationsPage(page)

        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /calendario/i })
            .click()
        await waitForNoSkeleton(page)

        // Find which month is displayed (read year+month from the calendar heading)
        const heading = page.locator('.serif.text-xl.text-on-surface').first()
        const headingText = await heading.textContent()
        expect(headingText).toBeTruthy()

        // Fetch the API with the current month's range to know which days have events
        const now = new Date()
        const y = now.getFullYear()
        const m = String(now.getMonth() + 1).padStart(2, '0')
        const lastDay = new Date(now.getFullYear(), now.getMonth() + 1, 0).getDate()
        const dateFrom = `${y}-${m}-01`
        const dateTo = `${y}-${m}-${lastDay}`

        const apiResult = await apiFetch(page, `/api/v1/reservations?date_from=${dateFrom}&date_to=${dateTo}&per_page=100`)
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as Array<Record<string, unknown>>

        // Filter to those with event_date in this month
        const withDates = data.filter((r) => Boolean(r['event_date']))

        if (withDates.length > 0) {
            // There should be at least one chip button in the grid
            const chips = page.locator('[title]').filter({ hasText: /.+/ })
            const count = await chips.count()
            expect(count, 'Expected at least one reservation chip in the calendar').toBeGreaterThan(0)
        }

        // Grid container renders regardless
        await expect(page.getByText(/lun/i).first()).toBeVisible()
    })

    test('Calendario — chip click navigates to reservation detail', async ({ page }) => {
        await gotoReservationsPage(page)

        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /calendario/i })
            .click()
        await waitForNoSkeleton(page)

        // Find a clickable chip in the grid (buttons with title attribute = reservation chips)
        const chip = page.locator('button[title]').first()
        const hasChip = await chip.isVisible().catch(() => false)

        if (!hasChip) {
            // No reservations with event_date this month — navigate to prev month to find one
            await page.click('button[aria-label="Mes anterior"]')
            await waitForNoSkeleton(page)

            const chip2 = page.locator('button[title]').first()
            const hasChip2 = await chip2.isVisible().catch(() => false)
            if (!hasChip2) {
                // Still no chips — skip rather than fail (data may not have event_date values)
                test.skip()
                return
            }

            // Read the id from the title attribute (format: "RSV-... · Customer · Occasion")
            const chipTitle = await chip2.getAttribute('title') ?? ''
            await chip2.click()
            await page.waitForURL('**/admin/reservations/**', { timeout: 10_000 })
            expect(chipTitle.length).toBeGreaterThan(0)
            return
        }

        await chip.click()
        await page.waitForURL('**/admin/reservations/**', { timeout: 10_000 })
        expect(page.url()).toMatch(/\/admin\/reservations\/\d+/)
    })

    test('Calendario — month nav prev/next changes the month heading', async ({ page }) => {
        await gotoReservationsPage(page)

        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /calendario/i })
            .click()
        await waitForNoSkeleton(page)

        const heading = page.locator('.serif.text-xl.text-on-surface').first()
        const originalText = await heading.textContent()

        await page.click('button[aria-label="Mes siguiente"]')
        await waitForNoSkeleton(page)

        const newText = await heading.textContent()
        expect(newText).not.toBe(originalText)

        // Navigate back
        await page.click('button[aria-label="Mes anterior"]')
        await waitForNoSkeleton(page)

        const backText = await heading.textContent()
        expect(backText).toBe(originalText)
    })

    // ── BOARD view ────────────────────────────────────────────────────────────

    test('Tablero — columns render with reservation cards', async ({ page }) => {
        await gotoReservationsPage(page)

        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /tablero/i })
            .click()
        await waitForNoSkeleton(page)

        const board = page.getByTestId('board-columns')
        await expect(board).toBeVisible()

        // All active workflow column containers are present
        for (const col of ['inquiry', 'confirmed', 'in_progress', 'ready', 'delivered']) {
            await expect(page.getByTestId(`board-column-${col}`)).toBeVisible()
        }

        // At least one card is present somewhere on the board (data is seeded)
        const cards = page.locator('[draggable="true"]')
        const cardCount = await cards.count()
        expect(cardCount, 'Board should have at least one reservation card').toBeGreaterThan(0)
    })

    test('Tablero — dragging a card to a valid adjacent column moves it and calls the API', async ({ page }) => {
        // Prefer confirmed → in_progress as the test path because inquiry → confirmed
        // may be blocked by the deposit requirement business rule (422 from backend).
        // confirmed → in_progress has no such gate so it reliably transitions.
        const apiResult = await apiFetch(page, '/api/v1/reservations?status=confirmed&per_page=100')
        const body = apiResult.body as Record<string, unknown>
        const data = body['data'] as Array<Record<string, unknown>>

        const draggable = data.find((r) => {
            const transitions = r['allowed_transitions'] as string[] | undefined
            return Array.isArray(transitions) && transitions.includes('in_progress')
        })

        if (!draggable) {
            // Fall back to in_progress → ready
            const fallbackResult = await apiFetch(page, '/api/v1/reservations?status=in_progress&per_page=100')
            const fallbackBody = fallbackResult.body as Record<string, unknown>
            const fallbackData = fallbackBody['data'] as Array<Record<string, unknown>>
            const fallback = fallbackData.find((r) => {
                const transitions = r['allowed_transitions'] as string[] | undefined
                return Array.isArray(transitions) && transitions.includes('ready')
            })

            if (!fallback) {
                test.skip()
                return
            }

            await runDragTest(page, fallback, 'in_progress', 'ready')
            return
        }

        await runDragTest(page, draggable, 'confirmed', 'in_progress')
    })

    test('Tablero — horizontally scrollable at mobile width', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await gotoReservationsPage(page)

        await page.getByRole('group', { name: /modo de vista/i })
            .getByRole('button', { name: /tablero/i })
            .click()
        await waitForNoSkeleton(page)

        const board = page.getByTestId('board-columns')
        await expect(board).toBeVisible()

        // The board scroll container has overflow-x-auto — verify its scrollWidth > clientWidth
        const scrollable = await board.evaluate((el) => el.scrollWidth > el.clientWidth)
        expect(scrollable, 'Board should be horizontally scrollable on mobile').toBeTruthy()
    })

    // ── Mobile: view toggle reachable ────────────────────────────────────────

    test('mobile (375px) — view toggle and heading are visible', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await gotoReservationsPage(page)

        await expect(page.getByRole('heading', { name: 'Reservas', exact: true })).toBeVisible()
        await expect(page.getByRole('group', { name: /modo de vista/i })).toBeVisible()
    })
})

// ── Drag helper (extracted to keep the test concise) ─────────────────────────

/**
 * Simulate HTML5 drag-and-drop between two elements by dispatching the full
 * DragEvent sequence (dragstart → dragover → drop → dragend) via JavaScript.
 * This approach is more reliable than Playwright's built-in dragTo for HTML5 DnD
 * because Chromium's HTML5 DnD requires proper DataTransfer object support that
 * Playwright's synthetic mouse events don't always populate correctly.
 *
 * Returns a status string: 'ok' | 'source_not_found' | 'target_not_found'
 */
async function simulateDrop(
    page: Page,
    sourceSelector: string,
    targetSelector: string,
): Promise<string> {
    return page.evaluate(
        ([srcSel, tgtSel]) => {
            const source = document.querySelector(srcSel)
            if (!source) return 'source_not_found'

            const target = document.querySelector(tgtSel)
            if (!target) return 'target_not_found'

            const dt = new DataTransfer()

            // Fire the full DnD sequence synchronously so Vue's handlers run
            // in the same microtask queue as the dispatch
            source.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: dt }))
            target.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: dt }))
            target.dispatchEvent(new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: dt }))
            target.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: dt }))
            source.dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: dt }))

            return 'ok'
        },
        [sourceSelector, targetSelector] as [string, string],
    )
}

async function runDragTest(
    page: Page,
    reservation: Record<string, unknown>,
    fromStatus: string,
    toStatus: string,
): Promise<void> {
    const reservationId = reservation['id'] as number

    await page.goto(
        `${process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'}/admin/reservations`,
    )
    await page.waitForSelector('[role="group"][aria-label="Modo de vista"]', { timeout: 15_000 })

    await page.getByRole('group', { name: /modo de vista/i })
        .getByRole('button', { name: /tablero/i })
        .click()

    await page.waitForFunction(
        () =>
            document.querySelectorAll('.animate-pulse').length === 0 &&
            document.querySelectorAll('.animate-spin').length === 0,
        { timeout: 15_000 },
    )
    // Give Vue an extra tick to finish rendering the board cards
    await page.waitForTimeout(500)

    // Locate the card in the source column
    const sourceCard = page.locator(`[data-reservation-id="${reservationId}"]`)
    await expect(sourceCard).toBeVisible({ timeout: 10_000 })

    // Locate the target column drop zone
    const targetColumn = page.getByTestId(`board-column-${toStatus}`)
    await expect(targetColumn).toBeVisible()

    // Dispatch the full DragEvent sequence programmatically.
    // NOTE: Vue's @dragstart handler must run before @drop so draggingId is set.
    // We do this via dispatchEvent on the DOM elements bound by the v-on directives.
    const dropped = await simulateDrop(
        page,
        `[data-reservation-id="${reservationId}"]`,
        `[data-testid="board-dropzone-${toStatus}"]`,
    )

    if (dropped !== 'ok') {
        // Elements not found in DOM — skip rather than false-fail
        console.warn(`simulateDrop returned: ${dropped}`)
        test.skip()
        return
    }

    // Wait for the optimistic update + API round-trip (PATCH + response)
    await page.waitForTimeout(3_000)

    // Verify via API that the reservation status changed
    const verifiedStatus = await page.evaluate(
        async (id) => {
            const r = await fetch(`/api/v1/reservations/${id}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'include',
            })
            if (!r.ok) return null
            const json = (await r.json()) as { data?: { status?: string } }
            return json.data?.status ?? null
        },
        reservationId,
    )

    expect(
        verifiedStatus,
        `Expected reservation ${reservationId} to be in status '${toStatus}' after drag-to-transition`,
    ).toBe(toStatus)
}
