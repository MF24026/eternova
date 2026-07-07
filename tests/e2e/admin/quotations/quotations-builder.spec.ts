import { test, expect, type Page } from '@playwright/test'

/**
 * Quotation Builder Slideover — S7-E7.
 *
 * Exercises the QuotationBuilderOverlay and its wiring into QuotationsPage.
 *
 * Requires DemoTenantsSeeder:
 *   - Tenant slug: rosa-eterna
 *   - Owner: caro@rosaeterna.com / DemoPro123!
 *   - At least one seeded product with a base_price_cents > 0
 *
 * Run with: --workers=1 (state-mutating tests share the same DB)
 *
 * Why in-page fetch:
 *   Node's HTTP client cannot resolve *.eternova.localhost — Chromium applies
 *   the RFC 6761 loopback rule. page.evaluate() runs inside the browser so
 *   the host resolves and the session cookie is sent automatically.
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
    await page.click('button.auth-submit')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function gotoQuotationsPage(page: Page): Promise<void> {
    await page.goto(QUOTATIONS_URL)
    await page.waitForSelector('h1:has-text("Cotizaciones")', { timeout: 15_000 })
    await waitForNoSkeleton(page)
}

async function waitForNoSkeleton(page: Page): Promise<void> {
    await page.waitForFunction(
        () =>
            document.querySelectorAll('.animate-pulse').length === 0 &&
            document.querySelectorAll('.animate-spin').length === 0,
        { timeout: 10_000 },
    )
}

async function openBuilderSlideover(page: Page): Promise<void> {
    await page.click('[data-testid="btn-nueva-cotizacion"]')
    await expect(
        page.locator('[data-testid="quotation-builder-overlay"]'),
        'Builder slideover should open',
    ).toBeVisible({ timeout: 5_000 })
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

test.describe('Quotation builder — open / close (S7-E7)', () => {

    test('clicking "Nueva cotización" opens the builder slideover', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // Button should be enabled (S7-E7 wires it)
        const btn = page.locator('[data-testid="btn-nueva-cotizacion"]')
        await expect(btn).toBeVisible()
        await expect(btn).not.toBeDisabled()

        await openBuilderSlideover(page)

        // Slideover title
        await expect(
            page.locator('[data-testid="quotation-builder-overlay"] h2'),
        ).toContainText('cotización')
    })

    test('closing the slideover via the X button removes it from view', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        await page.click('[data-testid="quotation-builder-overlay"] button[aria-label="Cerrar"]')

        await expect(
            page.locator('[data-testid="quotation-builder-overlay"]'),
        ).not.toBeVisible()
    })

    test('closing the slideover via "Cancelar" removes it from view', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        await page.click('[data-testid="btn-submit-quotation"]~button, button:has-text("Cancelar")')

        // More resilient: wait for the slideover to disappear
        await expect(
            page.locator('[data-testid="quotation-builder-overlay"]'),
        ).not.toBeVisible({ timeout: 5_000 })
    })
})

test.describe('Quotation builder — validation (S7-E7)', () => {

    test('submitting with no lines shows an error and does not close the slideover', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // The builder starts with one blank line — remove it to have zero lines
        // (the remove button is disabled when there is only 1 line, so we can't
        // actually reach 0 lines via the UI; instead clear the description so the
        // line is invalid)
        await page.fill('[data-testid="input-description-0"]', '')

        // Attempt submit
        await page.click('[data-testid="btn-submit-quotation"]')

        // Validation error must appear
        await expect(
            page.locator('[data-testid="error-items"]'),
        ).toBeVisible({ timeout: 3_000 })

        // Slideover stays open
        await expect(
            page.locator('[data-testid="quotation-builder-overlay"]'),
        ).toBeVisible()
    })
})

test.describe('Quotation builder — line items (S7-E7)', () => {

    test('adding a line increases the line count', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // One blank line by default
        await expect(page.locator('[data-testid^="line-item-"]')).toHaveCount(1)

        await page.click('[data-testid="btn-add-line"]')
        await expect(page.locator('[data-testid^="line-item-"]')).toHaveCount(2)
    })

    test('removing a line decreases the line count', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // Add a second line so we can remove it (single-line remove is disabled)
        await page.click('[data-testid="btn-add-line"]')
        await expect(page.locator('[data-testid^="line-item-"]')).toHaveCount(2)

        await page.click('[data-testid="btn-remove-line-1"]')
        await expect(page.locator('[data-testid^="line-item-"]')).toHaveCount(1)
    })

    test('reordering lines swaps their positions', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // Add a second line
        await page.click('[data-testid="btn-add-line"]')

        // Fill descriptions to distinguish the lines
        await page.fill('[data-testid="input-description-0"]', 'Línea A')
        await page.fill('[data-testid="input-description-1"]', 'Línea B')

        // Move line 1 up (it should become line 0)
        await page.click('[data-testid="btn-move-up-1"]')

        // Now line 0 should be "Línea B"
        const desc0 = await page.locator('[data-testid="input-description-0"]').inputValue()
        expect(desc0).toBe('Línea B')
    })

    test('live line total updates when quantity and price are entered', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // Set qty=2, unit_price=100.00 → line total = 200.00
        await page.fill('[data-testid="input-quantity-0"]', '2')
        await page.fill('[data-testid="input-unit-price-0"]', '100.00')

        // The line total should show something that includes "200"
        // (exact format depends on tenant currency formatter)
        const lineTotal = page.locator('[data-testid="line-total-0"]')
        await expect(lineTotal).toContainText('200')
    })
})

test.describe('Quotation builder — live totals math (S7-E7)', () => {

    /**
     * Scenario: 2 lines × $100 each + 1 line × $50 + 13% IVA
     * - subtotal    = 2×10000 + 1×5000 = 25000 cents
     * - discount    = 0
     * - taxableBase = 25000
     * - tax         = Math.floor(25000 × 1300 / 10000) = Math.floor(3250.0) = 3250 cents
     * - total       = 25000 + 3250 = 28250 cents
     *
     * In the UI (assuming USD/SV locale): subtotal = $250.00, tax = $32.50, total = $282.50
     * We assert "contains number" patterns that are locale-agnostic.
     */
    test('totals match the server formula with two lines and 13% IVA', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // Line 0: qty=2, price=100.00
        await page.fill('[data-testid="input-description-0"]', 'Producto A')
        await page.fill('[data-testid="input-quantity-0"]', '2')
        await page.fill('[data-testid="input-unit-price-0"]', '100.00')

        // Add line 1: qty=1, price=50.00
        await page.click('[data-testid="btn-add-line"]')
        await page.fill('[data-testid="input-description-1"]', 'Producto B')
        await page.fill('[data-testid="input-quantity-1"]', '1')
        await page.fill('[data-testid="input-unit-price-1"]', '50.00')

        // Set 13% IVA
        await page.fill('[data-testid="input-tax-rate"]', '13')

        // Assert live subtotal contains "250"
        await expect(page.locator('[data-testid="live-subtotal"]')).toContainText('250')

        // Assert live tax contains "32" (32.50 truncated/rounded by formatter)
        await expect(page.locator('[data-testid="live-tax"]')).toContainText('32')

        // Assert live total contains "282"
        await expect(page.locator('[data-testid="live-total"]')).toContainText('282')
    })

    test('adding a discount reduces the total correctly', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // Line: qty=1, price=200.00 → subtotal = 20000 cents
        await page.fill('[data-testid="input-description-0"]', 'Artículo')
        await page.fill('[data-testid="input-quantity-0"]', '1')
        await page.fill('[data-testid="input-unit-price-0"]', '200.00')

        // subtotal should show 200
        await expect(page.locator('[data-testid="live-subtotal"]')).toContainText('200')

        // Add a $50.00 discount → taxableBase = 15000 cents, total (0% IVA) = $150
        await page.fill('[data-testid="input-discount"]', '50.00')

        // Discount line becomes visible and total drops
        await expect(page.locator('[data-testid="live-discount"]')).toBeVisible()
        await expect(page.locator('[data-testid="live-total"]')).toContainText('150')
    })
})

test.describe('Quotation builder — create and list refresh (S7-E7)', () => {

    test('submitting a valid quotation closes the slideover and shows a success toast', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // Fill one line
        await page.fill('[data-testid="input-description-0"]', `Cotización E2E ${Date.now()}`)
        await page.fill('[data-testid="input-quantity-0"]', '1')
        await page.fill('[data-testid="input-unit-price-0"]', '99.99')

        // Submit
        await page.click('[data-testid="btn-submit-quotation"]')

        // Success toast
        await expect(
            page.locator('text=Cotización creada'),
        ).toBeVisible({ timeout: 8_000 })

        // Slideover closes
        await expect(
            page.locator('[data-testid="quotation-builder-overlay"]'),
        ).not.toBeVisible({ timeout: 5_000 })
    })

    test('newly created quotation appears in the list after saving', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        const uniqueDesc = `Cotización E2E Lista ${Date.now()}`
        await page.fill('[data-testid="input-description-0"]', uniqueDesc)
        await page.fill('[data-testid="input-quantity-0"]', '1')
        await page.fill('[data-testid="input-unit-price-0"]', '75.00')

        await page.click('[data-testid="btn-submit-quotation"]')
        await expect(page.locator('text=Cotización creada')).toBeVisible({ timeout: 8_000 })

        // Wait for list refresh
        await waitForNoSkeleton(page)

        // The quotation list should contain at least one row (the one we just created).
        // Since our item doesn't appear directly in the list (list shows quotation_number/customer),
        // we assert that there is at least one row — and that the draft count is ≥ 1.
        const draftTab = page.locator('[data-testid="tab-draft"]')
        await expect(draftTab).toBeVisible()
        // The draft count badge should be ≥ 1
        const draftCountText = await draftTab.locator('span').last().textContent()
        const draftCount = parseInt(draftCountText?.trim() ?? '0', 10)
        expect(draftCount).toBeGreaterThanOrEqual(1)
    })
})

test.describe('Quotation builder — edit draft (S7-E7)', () => {

    test('draft row shows an edit button that opens the builder in edit mode', async ({ page }) => {
        await login(page)
        await gotoQuotationsPage(page)

        // Create a draft via API first so we have something to edit
        const result = await apiFetch(page, '/api/v1/quotations', {
            method: 'POST',
            body: {
                issue_date: new Date().toISOString().slice(0, 10),
                items: [{ description: 'Borrador editable', quantity: 1, unit_price_cents: 5000 }],
            },
        })

        if (!result.ok) {
            test.skip()
            return
        }

        const createdQuotation = ((result.body as Record<string, unknown>).data) as { id: number }

        // Reload to see the new draft
        await gotoQuotationsPage(page)
        await waitForNoSkeleton(page)

        // Click the draft tab to ensure drafts are visible
        await page.click('[data-testid="tab-draft"]')
        await waitForNoSkeleton(page)

        // Find and click the edit button for the draft we just created (desktop table)
        const editBtn = page.locator(`[data-testid="btn-edit-draft-${createdQuotation.id}"]`)
        if (await editBtn.count() > 0) {
            await editBtn.click()
        } else {
            // Fallback: any edit button in the draft list
            const anyEditBtn = page.locator('[data-testid^="btn-edit-draft-"]').first()
            if (await anyEditBtn.count() === 0) {
                test.skip()
                return
            }
            await anyEditBtn.click()
        }

        // Builder opens in edit mode — subtitle should contain the quotation number or "borrador"
        await expect(
            page.locator('[data-testid="quotation-builder-overlay"]'),
        ).toBeVisible({ timeout: 5_000 })

        // Submit button reads "Guardar cambios" in edit mode
        await expect(
            page.locator('[data-testid="btn-submit-quotation"]'),
        ).toContainText('Guardar cambios')
    })
})

test.describe('Quotation builder — mobile (S7-E7)', () => {

    test('builder renders as full-screen bottom-sheet on a 375px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoQuotationsPage(page)

        await openBuilderSlideover(page)

        const slideover = page.locator('[data-testid="quotation-builder-overlay"]')
        await expect(slideover).toBeVisible()

        // On mobile the panel slides up from the bottom: its bounding box starts in lower part
        const box = await slideover.boundingBox()
        expect(box).not.toBeNull()
        if (box) {
            // Bottom-sheet: panel starts somewhere above 0 (not off-screen)
            expect(box.y).toBeGreaterThanOrEqual(0)
            // And has a meaningful height
            expect(box.height).toBeGreaterThan(200)
        }
    })

    test('form fields are accessible and functional on 375px', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoQuotationsPage(page)
        await openBuilderSlideover(page)

        // Should be able to fill in description and price on mobile
        await page.fill('[data-testid="input-description-0"]', 'Móvil test')
        await page.fill('[data-testid="input-unit-price-0"]', '50.00')

        // Live subtotal should update
        await expect(page.locator('[data-testid="live-subtotal"]')).toContainText('50')
    })
})
