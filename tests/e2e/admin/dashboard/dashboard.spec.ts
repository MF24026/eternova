import { test, expect, type Page } from '@playwright/test'

/**
 * Dashboard page — S9-E2.
 *
 * Verifies the real (API-driven) dashboard: KPI cards, Chart.js sales chart,
 * range switching, top products + recent orders panels, and mobile layout.
 *
 * Requires DemoTenantsSeeder (orders/expenses/inventory seeded). Run --workers=1.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const DASHBOARD_URL = `${TENANT_BASE}/admin/dashboard`

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function gotoDashboard(page: Page): Promise<void> {
    await page.goto(DASHBOARD_URL)
    await expect(page.locator('[data-testid="dashboard-root"]')).toBeVisible({ timeout: 15_000 })
}

test.describe('Dashboard page (S9-E2)', () => {

    test('renders four KPI cards, the sales chart, top products and recent orders', async ({ page }) => {
        await login(page)
        await gotoDashboard(page)

        await expect(page.locator('[data-testid="kpi-card"]')).toHaveCount(4)
        // Chart.js renders a <canvas> inside the sales-chart card.
        await expect(page.locator('[data-testid="sales-chart"] canvas')).toBeVisible()
        await expect(page.locator('[data-testid="top-products"]')).toBeVisible()
        await expect(page.locator('[data-testid="recent-orders"]')).toBeVisible()
    })

    test('shows the restock-needs card; its CTA opens inventory filtered to low stock', async ({ page }) => {
        await login(page)
        await gotoDashboard(page)

        const card = page.locator('[data-testid="low-stock-card"]')
        await expect(card).toBeVisible()
        await expect(card).toContainText('Reposición necesaria')

        const seeAll = card.locator('[data-testid="low-stock-see-all"]')
        if (await seeAll.count() > 0) {
            // There are low-stock items: the CTA arms the inventory low-stock filter.
            await seeAll.click()
            await expect(page).toHaveURL(/\/admin\/inventory\?low_stock=1/)
            await expect(page.locator('[data-testid="filter-low-stock"]')).toHaveClass(/bg-warning-container/)
        } else {
            // No low-stock items: the empty state is shown instead of the CTA.
            await expect(card).toContainText('Todo con stock suficiente')
        }
    })

    test('switching the chart range reloads and updates the active tab', async ({ page }) => {
        await login(page)
        await gotoDashboard(page)

        await expect(page.locator('[data-testid="range-14"]')).toHaveClass(/active/)

        await page.click('[data-testid="range-30"]')
        await expect(page.locator('[data-testid="range-30"]')).toHaveClass(/active/, { timeout: 8_000 })
        // Header reflects the new range.
        await expect(page.locator('[data-testid="sales-chart"]')).toContainText('últimos 30 días')
        // Chart still renders after reload.
        await expect(page.locator('[data-testid="sales-chart"] canvas')).toBeVisible()
    })

    test('KPI cards show formatted values (currency + counts)', async ({ page }) => {
        await login(page)
        await gotoDashboard(page)

        // First card is "Ventas hoy" — a currency value.
        const firstKpi = page.locator('[data-testid="kpi-card"]').first()
        await expect(firstKpi).toContainText('Ventas hoy')
        await expect(firstKpi).toContainText('$')
    })

    test('renders on a 375px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoDashboard(page)

        await expect(page.locator('[data-testid="kpi-card"]')).toHaveCount(4)
        await expect(page.locator('[data-testid="sales-chart"] canvas')).toBeVisible()
    })
})
