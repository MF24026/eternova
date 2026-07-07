import { test, expect, type Page } from '@playwright/test'

/**
 * Storefront quick-add — a product grid card has an "Agregar" button that opens
 * a bottom-sheet (variant + quantity) and adds to the cart WITHOUT leaving the
 * grid. Public storefront on the tenant subdomain (only Chromium resolves it).
 */
const ROSA_BASE = process.env.STOREFRONT_ROSA_URL ?? 'http://rosa-eterna.eternova.localhost'

async function waitForStorefront(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const app = document.querySelector('#app')
            return !!app && app.children.length > 0
        },
        { timeout: 20_000 },
    )
}

test('quick-add from the grid adds to the cart without navigating away', async ({ page }) => {
    await page.goto(`${ROSA_BASE}/`)
    await waitForStorefront(page)

    const addBtn = page.locator('[data-testid^="quick-add-btn-"]').first()
    await expect(addBtn).toBeVisible({ timeout: 20_000 })
    await addBtn.click()

    // Sheet opens and resolves a default variant (fetch completes).
    const sheet = page.locator('[data-testid="quick-add-sheet"]')
    await expect(sheet).toBeVisible()
    const confirm = sheet.locator('[data-testid="quick-add-confirm"]')
    await expect(confirm).toBeEnabled({ timeout: 10_000 })

    await confirm.click()

    // Sheet closes, we did NOT navigate to the product detail, and the cart badge
    // now shows one item.
    await expect(sheet).toBeHidden()
    await expect(page).toHaveURL(`${ROSA_BASE}/`)
    await expect(page.locator('[data-testid="cart-icon-btn"] span')).toHaveText('1')
})
