import { test, expect, type Page } from '@playwright/test'

/**
 * U1 (frontend-polish-batch) — AppTable must reflow to stacked cards on mobile.
 *
 * Regression guard: below `md`, AppTable rendered a `<table>` in an
 * `overflow-x-auto` wrapper, so at 375px the table scrolled horizontally and
 * hid key columns (Total, Estado, Asignado) off-screen. The fix hides the table
 * and renders a stacked label:value card per row that reuses the same cell
 * slots, so every AppTable consumer (Orders, Expenses, Quotations, Reservations,
 * Inventory, Movements) benefits.
 *
 * We exercise the Orders list on the seeded rosa-eterna tenant.
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

test.describe('AppTable responsive (mobile cards)', () => {
    test('orders list reflows to cards on mobile, showing Total and Estado', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)

        await page.goto(`${TENANT_BASE}/admin/orders`)

        const cards = page.locator('[data-testid="app-table-mobile-cards"]')
        await expect(cards).toBeVisible({ timeout: 15_000 })

        // The desktop table is hidden below md.
        await expect(page.locator('[data-testid="app-table-desktop"]')).toBeHidden()

        // At least one order card rendered.
        const firstCard = cards.locator('> div').first()
        await expect(firstCard).toBeVisible()

        // Total and Estado — the columns that used to be scrolled off-screen —
        // are present as labels inside the card.
        await expect(firstCard.getByText('Total', { exact: true })).toBeVisible()
        await expect(firstCard.getByText('Estado', { exact: true })).toBeVisible()

        // The card list itself does not overflow horizontally (the whole point:
        // the row data now fits the viewport instead of scrolling sideways).
        const cardsOverflow = await cards.evaluate((el) => el.scrollWidth > el.clientWidth + 1)
        expect(cardsOverflow, 'card list should fit without horizontal scroll').toBe(false)
    })

    test('desktop keeps the classic table', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 })
        await login(page)

        await page.goto(`${TENANT_BASE}/admin/orders`)

        await expect(page.locator('[data-testid="app-table-desktop"]')).toBeVisible({ timeout: 15_000 })
        await expect(page.locator('[data-testid="app-table-mobile-cards"]')).toBeHidden()
    })
})
