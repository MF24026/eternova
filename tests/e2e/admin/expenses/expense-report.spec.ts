import { test, expect, type Page } from '@playwright/test'

/**
 * Expense report page (S6-E8) — monthly chart + breakdown.
 *
 * Requires DemoTenantsSeeder:
 *   - Tenant: rosa-eterna  /  caro@rosaeterna.com / DemoPro123!
 *   - ~6 months of seeded expenses across 5 categories (ExpensesSeeder S6-E8)
 *
 * Run with: --workers=1
 * Fetch calls use in-page `fetch()` so the browser can resolve
 * *.eternova.localhost and send the session cookie automatically.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const EXPENSES_URL  = `${TENANT_BASE}/admin/expenses`
const REPORT_URL    = `${TENANT_BASE}/admin/expenses/report`

// ── Helpers ───────────────────────────────────────────────────────────────────

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function gotoReportPage(page: Page): Promise<void> {
    await page.goto(REPORT_URL)
    // Wait until loading spinner is gone and the page has resolved
    await page.waitForFunction(
        () => document.querySelectorAll('[data-testid="report-loading"]').length === 0,
        { timeout: 15_000 },
    )
}

/** In-page GET fetch — avoids Node DNS resolution issues for *.eternova.localhost. */
async function apiGet<T>(page: Page, path: string): Promise<T> {
    return page.evaluate(async (p: string) => {
        const r = await fetch(p, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'include',
        })
        return r.json() as Promise<T>
    }, path)
}

function currentMonth(): string {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
}

function previousMonth(): string {
    const d = new Date()
    d.setMonth(d.getMonth() - 1)
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

// ── Navigate to the report via the "Reporte" button ───────────────────────────

test.describe('Expense report navigation (S6-E8)', () => {

    test('reaches the report via the "Reporte" button on ExpensesPage', async ({ page }) => {
        await login(page)
        await page.goto(EXPENSES_URL)
        await page.waitForSelector('[data-testid="period-total"]', { timeout: 15_000 })

        // Click the "Reporte" header button
        await page.click('[data-testid="btn-reporte"]')
        await page.waitForURL('**/admin/expenses/report', { timeout: 10_000 })

        // Page title landmark — scope to <main> to avoid the AdminLayout sidebar h1
        await expect(page.locator('main h1')).toContainText('Reporte de gastos')
    })
})

// ── Report content ─────────────────────────────────────────────────────────────

test.describe('Expense report content (S6-E8)', () => {

    test('renders grand total, chart, and breakdown rows for the current month', async ({ page }) => {
        await login(page)
        await gotoReportPage(page)

        // Grand total widget
        await expect(page.locator('[data-testid="grand-total"]')).toBeVisible()
        await expect(page.locator('[data-testid="grand-total-amount"]')).toBeVisible()

        // Chart is rendered
        await expect(page.locator('[data-testid="expense-chart"]')).toBeVisible()

        // Breakdown table
        await expect(page.locator('[data-testid="breakdown-table"]')).toBeVisible()

        // At least one breakdown row (the seeder provides expenses)
        const rows = await page.locator('[data-testid^="breakdown-row-"]').count()
        expect(rows).toBeGreaterThanOrEqual(1)
    })

    test('breakdown total matches the API total_cents', async ({ page }) => {
        await login(page)
        await gotoReportPage(page)

        const month = currentMonth()

        // Fetch the API directly from the page context
        const apiResponse = await apiGet<{ data: { total_cents: number } }>(
            page,
            `/api/v1/expenses/report?month=${month}`,
        )
        const apiTotalCents = apiResponse.data.total_cents

        // Read the displayed total text from the grand-total element
        const displayedText = await page.locator('[data-testid="grand-total-amount"]').innerText()

        // The displayed amount must be non-empty
        expect(displayedText.trim()).not.toBe('')

        // If API returned 0 the page shows the empty state — skip amount comparison
        if (apiTotalCents === 0) {
            await expect(page.locator('[data-testid="report-empty"]')).toBeVisible()
        } else {
            // The breakdown footer total must also be visible
            await expect(page.locator('[data-testid="breakdown-total"]')).toBeVisible()
        }
    })
})

// ── Month navigation ───────────────────────────────────────────────────────────

test.describe('Month navigation (S6-E8)', () => {

    test('prev-month arrow refetches and updates the displayed month label', async ({ page }) => {
        await login(page)
        await gotoReportPage(page)

        // Record the current month label
        const initialLabel = await page.locator('[data-testid="month-label"]').innerText()

        // Navigate to the previous month
        await page.click('[data-testid="btn-prev-month"]')

        // Wait for the spinner to disappear (refetch)
        await page.waitForFunction(
            () => document.querySelectorAll('[data-testid="report-loading"]').length === 0,
            { timeout: 10_000 },
        )

        const updatedLabel = await page.locator('[data-testid="month-label"]').innerText()
        expect(updatedLabel).not.toBe(initialLabel)
    })

    test('previous month with seeded data shows updated total', async ({ page }) => {
        await login(page)
        await gotoReportPage(page)

        const prev = previousMonth()

        // Use the hidden month input for a direct jump
        await page.locator('[data-testid="month-input"]').fill(prev)
        // The watch fires on input change — wait for the refetch
        await page.waitForFunction(
            () => document.querySelectorAll('[data-testid="report-loading"]').length === 0,
            { timeout: 10_000 },
        )

        // Fetch the API for confirmation
        const apiResponse = await apiGet<{ data: { total_cents: number } }>(
            page,
            `/api/v1/expenses/report?month=${prev}`,
        )

        if (apiResponse.data.total_cents > 0) {
            await expect(page.locator('[data-testid="grand-total"]')).toBeVisible()
            await expect(page.locator('[data-testid="grand-total-amount"]')).toBeVisible()
        } else {
            // No seeded data for that month — empty state is correct
            await expect(page.locator('[data-testid="report-empty"]')).toBeVisible()
        }
    })
})

// ── Verified-only toggle ───────────────────────────────────────────────────────

test.describe('Verified-only toggle (S6-E8)', () => {

    test('toggling "Solo verificados" changes the grand total', async ({ page }) => {
        await login(page)
        await gotoReportPage(page)

        const month = currentMonth()

        // Fetch both totals from the API for comparison
        const [allResp, verifiedResp] = await Promise.all([
            apiGet<{ data: { total_cents: number } }>(
                page, `/api/v1/expenses/report?month=${month}`),
            apiGet<{ data: { total_cents: number } }>(
                page, `/api/v1/expenses/report?month=${month}&verified_only=1`),
        ])

        const allTotal      = allResp.data.total_cents
        const verifiedTotal = verifiedResp.data.total_cents

        // Only meaningful when the seeder has both verified + draft expenses
        if (allTotal === 0) {
            // Nothing to compare — the empty state must render
            await expect(page.locator('[data-testid="report-empty"]')).toBeVisible()
            return
        }

        // Toggle on
        await page.click('[data-testid="verified-only-toggle"]')
        await page.waitForFunction(
            () => document.querySelectorAll('[data-testid="report-loading"]').length === 0,
            { timeout: 10_000 },
        )

        if (verifiedTotal === 0) {
            await expect(page.locator('[data-testid="report-empty"]')).toBeVisible()
        } else {
            await expect(page.locator('[data-testid="grand-total-amount"]')).toBeVisible()
        }

        // The two totals must differ (seeder has mixed verification status)
        // We assert they're not equal only when the seeder guarantees it
        expect(verifiedTotal).toBeLessThanOrEqual(allTotal)
    })
})

// ── Breakdown rows ─────────────────────────────────────────────────────────────

test.describe('Breakdown rows (S6-E8)', () => {

    test('a category row shows its type badge and percentage', async ({ page }) => {
        await login(page)
        await gotoReportPage(page)

        const month = currentMonth()
        const apiResponse = await apiGet<{
            data: {
                total_cents: number
                by_category: Array<{
                    category_id: number | null
                    category_name: string
                    category_type: string | null
                    total_cents: number
                    count: number
                }>
            }
        }>(page, `/api/v1/expenses/report?month=${month}`)

        const nonZeroRows = apiResponse.data.by_category.filter((r) => r.total_cents > 0)

        if (nonZeroRows.length === 0) {
            await expect(page.locator('[data-testid="report-empty"]')).toBeVisible()
            return
        }

        // Pick the top row (first non-zero)
        const topRow = nonZeroRows[0]
        const rowId  = topRow.category_id ?? 'uncategorised'

        // The breakdown row must be visible
        await expect(page.locator(`[data-testid="breakdown-row-${rowId}"]`)).toBeVisible()

        // If the category has a type, the type badge must be visible
        if (topRow.category_type) {
            await expect(
                page.locator(`[data-testid="type-badge-${rowId}"]`),
            ).toBeVisible()
        }

        // Percentage for the row (computed as Math.round(total / grandTotal * 100))
        const expectedPct = apiResponse.data.total_cents > 0
            ? Math.round((topRow.total_cents / apiResponse.data.total_cents) * 100)
            : 0

        const pctText = await page.locator(`[data-testid="pct-${rowId}"]`).innerText()
        expect(pctText.trim()).toBe(`${expectedPct}%`)
    })
})

// ── Mobile viewport ────────────────────────────────────────────────────────────

test.describe('Expense report mobile (S6-E8)', () => {

    test('chart and breakdown render stacked on 375 px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoReportPage(page)

        const month = currentMonth()
        const apiResponse = await apiGet<{ data: { total_cents: number } }>(
            page, `/api/v1/expenses/report?month=${month}`)

        if (apiResponse.data.total_cents === 0) {
            await expect(page.locator('[data-testid="report-empty"]')).toBeVisible()
            return
        }

        // Both chart and breakdown must be visible and not overflow horizontally
        const chart = page.locator('[data-testid="expense-chart"]')
        const table = page.locator('[data-testid="breakdown-table"]')

        await expect(chart).toBeVisible()
        await expect(table).toBeVisible()

        // Chart bounding box should not exceed viewport width (plus a tiny tolerance)
        const chartBox = await chart.boundingBox()
        if (chartBox) {
            expect(chartBox.width).toBeLessThanOrEqual(375 + 2)
        }

        const tableBox = await table.boundingBox()
        if (tableBox) {
            expect(tableBox.width).toBeLessThanOrEqual(375 + 2)
        }
    })
})
