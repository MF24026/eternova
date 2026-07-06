import { test, expect, type Page } from '@playwright/test'

/**
 * U5 (frontend-polish-batch) — the sales chart shows an empty-state instead of a
 * bare grid when there are no sales in the selected range.
 *
 * Regression guard: with an all-zero series, Chart.js drew a grid with a flat
 * line pinned to the axis, which reads as broken. The fix renders a centered
 * "Sin ventas en este periodo." message (matching the sibling panels) and no
 * canvas.
 *
 * We log in to the seeded tenant (so the dashboard shell + auth are real) and
 * intercept the dashboard API to force an all-zero sales_series. That makes the
 * empty state deterministic without depending on when the demo seeder ran (the
 * seeded data has recent sales; the non-empty path is covered by dashboard.spec.ts).
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button.auth-submit')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

test.describe('Dashboard sales chart empty state (U5)', () => {
    test('shows empty-state message and no canvas when the series is all zero', async ({ page }) => {
        await login(page)

        // Grab the real summary in-page (only Chromium resolves the subdomain),
        // then zero its series to build a deterministic empty-sales payload.
        const emptyBody = await page.evaluate(async () => {
            const r = await fetch('/api/v1/dashboard?range=14', {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'include',
            })
            const json = await r.json()
            if (Array.isArray(json?.data?.sales_series)) {
                json.data.sales_series = json.data.sales_series.map(
                    (p: { date: string; total_cents: number }) => ({ ...p, total_cents: 0 }),
                )
            }
            return JSON.stringify(json)
        })

        // Serve the all-zero series for every dashboard summary request.
        await page.route('**/api/v1/dashboard**', (route) =>
            route.fulfill({ status: 200, contentType: 'application/json', body: emptyBody }),
        )

        await page.goto(`${TENANT_BASE}/admin/dashboard`)
        await expect(page.locator('[data-testid="dashboard-root"]')).toBeVisible({ timeout: 15_000 })

        const empty = page.locator('[data-testid="sales-chart-empty"]')
        await expect(empty).toBeVisible({ timeout: 10_000 })
        await expect(empty).toContainText('Sin ventas')
        // The bare Chart.js canvas must not render for an empty series.
        await expect(page.locator('[data-testid="sales-chart"] canvas')).toHaveCount(0)
    })
})
