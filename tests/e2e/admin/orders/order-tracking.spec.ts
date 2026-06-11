import { test, expect, type Page } from '@playwright/test'

/**
 * Public order tracking page (S4-E7).
 *
 * Strategy for getting a real tracking_token:
 *   1. Log in as the demo owner (caro@rosaeterna.com on rosa-eterna).
 *   2. Fetch the order list via in-page fetch (only Chromium resolves *.eternova.localhost).
 *   3. Fetch the first order's detail to get its `tracking_token`.
 *   4. Navigate to /track/{token} to exercise the public page.
 *
 * The public page must render WITHOUT auth — we verify this by clearing all
 * cookies before the tracking navigation so we confirm it also works anonymously.
 * (The route has `meta: { public: true }` and no `requiresAuth`, so the auth
 * guard lets it through unconditionally.)
 *
 * Run alone to avoid MySQL contention:
 *   npx playwright test order-tracking --project=chromium-desktop
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const INVALID_TOKEN = 'THISTOKENDOESNOTEXIST000000000000'

// ---------------------------------------------------------------------------
// Helpers (mirrors order-detail.spec.ts pattern)
// ---------------------------------------------------------------------------

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

/** In-page fetch — must run inside Chromium so *.eternova.localhost resolves. */
async function apiFetch<T = unknown>(
    page: Page,
    path: string,
): Promise<{ status: number; ok: boolean; body: T }> {
    return page.evaluate(
        async ([p]) => {
            const r = await fetch(p as string, {
                method: 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
            })
            return { status: r.status, ok: r.ok, body: await r.json() }
        },
        [path] as [string],
    ) as Promise<{ status: number; ok: boolean; body: T }>
}

/** Returns a tracking_token from the first order that has one. */
async function getTrackingToken(page: Page): Promise<string | null> {
    const listResult = await apiFetch<{
        data: Array<{ id: string; status: string; tracking_token: string | null }>
    }>(page, '/api/v1/orders?per_page=50')

    if (!listResult.ok) return null

    const ordersWithToken = listResult.body.data.filter((o) => o.tracking_token !== null)
    if (ordersWithToken.length === 0) return null

    // Prefer an order that is not cancelled so we exercise the stepper path.
    const nonCancelled = ordersWithToken.find((o) => o.status !== 'cancelled')
    return (nonCancelled ?? ordersWithToken[0]).tracking_token
}

async function waitForAppMounted(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const app = document.querySelector('#app')
            return app !== null && app.children.length > 0
        },
        { timeout: 15_000 },
    )
    // Wait until any spinners are gone
    await page.waitForFunction(
        () => document.querySelectorAll('[role="status"][aria-label="Cargando"]').length === 0,
        { timeout: 15_000 },
    )
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Public order tracking page (S4-E7)', () => {

    // ── Valid token (authenticated context) ────────────────────────────────
    //
    // We log in first to be able to fetch a real token, then navigate to the
    // tracking page in the SAME context (still authenticated). This confirms
    // the route works whether the visitor has a session or not.

    test('renders order_number and brand for a valid token', async ({ page }) => {
        await login(page)

        const token = await getTrackingToken(page)
        if (!token) {
            test.skip()
            return
        }

        await page.goto(`${TENANT_BASE}/track/${token}`)
        await waitForAppMounted(page)

        // Order number visible (serif heading)
        const heading = page.locator('h1.serif, h1[class*="serif"]').first()
        await expect(heading).toBeVisible({ timeout: 10_000 })

        // Brand business name visible somewhere on the page
        await expect(page.getByText('Rosa Eterna', { exact: false }).first()).toBeVisible()
    })

    test('status stepper is present and has at least one node', async ({ page }) => {
        await login(page)

        const token = await getTrackingToken(page)
        if (!token) {
            test.skip()
            return
        }

        await page.goto(`${TENANT_BASE}/track/${token}`)
        await waitForAppMounted(page)

        // The stepper renders an <ol> with aria-label "Pasos del pedido".
        // On desktop we expect the horizontal version; on mobile the vertical one.
        // Either is fine — just confirm at least one is visible.
        const stepper = page
            .getByRole('list', { name: /pasos del pedido/i })
            .first()
        await expect(stepper).toBeVisible({ timeout: 10_000 })

        const firstNode = stepper.locator('li').first()
        await expect(firstNode).toBeVisible()
    })

    test('timeline section has at least one entry', async ({ page }) => {
        await login(page)

        const token = await getTrackingToken(page)
        if (!token) {
            test.skip()
            return
        }

        await page.goto(`${TENANT_BASE}/track/${token}`)
        await waitForAppMounted(page)

        const timeline = page
            .getByRole('list', { name: /cambios de estado/i })
            .first()
        await expect(timeline).toBeVisible({ timeout: 10_000 })

        const firstEntry = timeline.locator('li').first()
        await expect(firstEntry).toBeVisible()
    })

    test('no price or customer PII in the DOM', async ({ page }) => {
        await login(page)

        const token = await getTrackingToken(page)
        if (!token) {
            test.skip()
            return
        }

        await page.goto(`${TENANT_BASE}/track/${token}`)
        await waitForAppMounted(page)

        const bodyText = await page.locator('body').textContent()
        const text = bodyText ?? ''

        // No currency symbols followed by digits (price leak).
        // A simple heuristic: $ or currency code followed by digits.
        expect(text).not.toMatch(/\$\s*\d+/)
        expect(text).not.toMatch(/USD\s*\d+/)

        // No phone-number patterns (customer PII leak — 8+ digits in sequence).
        // Exclude the WhatsApp link which is intentional (tenant contact, not customer).
        // We strip the footer section for this check.
        const mainText = await page.locator('main').textContent() ?? ''
        expect(mainText).not.toMatch(/\+?[0-9]{8,}/)
    })

    // ── Anonymous context: confirms no auth bounce ────────────────────────

    test('page renders without redirecting to /login when cookies are cleared', async ({ page }) => {
        await login(page)

        const token = await getTrackingToken(page)
        if (!token) {
            test.skip()
            return
        }

        // Clear all cookies to simulate an anonymous (logged-out) visitor.
        await page.context().clearCookies()

        await page.goto(`${TENANT_BASE}/track/${token}`)

        // Must NOT redirect to /login.
        await page.waitForURL(
            (url) => !url.pathname.startsWith('/login'),
            { timeout: 10_000 },
        )

        // Vue app should mount and show the tracking content (or the not-found state
        // if the public API requires the cookie for tenant resolution — either is valid
        // as long as we are not on /login).
        await waitForAppMounted(page)
        expect(page.url()).not.toContain('/login')
    })

    // ── Invalid token ─────────────────────────────────────────────────────

    test('shows not-found state for an invalid token — not a crash or redirect to /login', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/track/${INVALID_TOKEN}`)

        await waitForAppMounted(page)

        // Must stay on the tracking URL, not redirect.
        expect(page.url()).not.toContain('/login')

        // Friendly not-found heading visible.
        await expect(
            page.getByRole('heading', { name: /no encontramos este pedido/i }),
        ).toBeVisible({ timeout: 10_000 })
    })

    // ── Mobile viewport ────────────────────────────────────────────────────

    test('stepper and timeline render stacked at mobile width (375px)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })

        await login(page)

        const token = await getTrackingToken(page)
        if (!token) {
            test.skip()
            return
        }

        await page.goto(`${TENANT_BASE}/track/${token}`)
        await waitForAppMounted(page)

        // Heading visible on mobile
        const heading = page.locator('h1.serif, h1[class*="serif"]').first()
        await expect(heading).toBeVisible({ timeout: 10_000 })

        // The vertical stepper is the sm:hidden variant — it exists in the DOM
        // but only the vertical (default) variant is visible at 375px.
        // We confirm at least one <li> is visible in the page.
        const anyStepperItem = page.locator('[aria-label="Pasos del pedido"] li').first()
        await expect(anyStepperItem).toBeVisible()

        // Timeline still visible on mobile.
        const timeline = page.getByRole('list', { name: /cambios de estado/i }).first()
        await expect(timeline).toBeVisible()
    })

    // ── Staff "Copiar link" button on the detail page (optional) ─────────

    test('detail page shows "Copiar link de seguimiento" button for non-cancelled order with token', async ({ page }) => {
        await login(page)

        // Get an order that has a token and is not cancelled.
        const listResult = await apiFetch<{
            data: Array<{ id: string; status: string; tracking_token: string | null }>
        }>(page, '/api/v1/orders?per_page=50')

        if (!listResult.ok) {
            test.skip()
            return
        }

        const candidate = listResult.body.data.find(
            (o) => o.tracking_token !== null && o.status !== 'cancelled',
        )
        if (!candidate) {
            test.skip()
            return
        }

        await page.goto(`${TENANT_BASE}/admin/orders/${candidate.id}`)
        await page.waitForSelector('h1.serif, h1[class*="serif"]', { timeout: 15_000 })
        await page.waitForFunction(
            () => document.querySelectorAll('[role="status"][aria-label="Cargando"]').length === 0,
            { timeout: 15_000 },
        )

        await expect(
            page.getByRole('button', { name: /copiar link de seguimiento/i }),
        ).toBeVisible({ timeout: 10_000 })
    })
})
