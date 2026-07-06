import { test, expect, type Page } from '@playwright/test'

/**
 * U4 (frontend-polish-batch) — product image fallback is unified.
 *
 * Regression guard: a product with no image fell back to a flat gradient in POS
 * but to the richer <Surrogate> botanical illustration in the storefront — the
 * same missing-image state looked different on each surface. The shared
 * <ProductImage> now renders the Surrogate illustration everywhere, so both
 * surfaces show the same `.surrogate` fallback for image-less demo products.
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

test.describe('unified product image fallback', () => {
    test('storefront featured products show the Surrogate fallback', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/`)
        const grid = page.locator('[data-testid="featured-products-grid"]')
        await expect(grid).toBeVisible({ timeout: 20_000 })

        // Demo products have no images -> every card shows the shared illustration.
        await expect(grid.locator('.surrogate').first()).toBeVisible()
    })

    test('POS tiles show the same Surrogate fallback (not a plain gradient)', async ({ page }) => {
        await login(page)
        await page.goto(`${TENANT_BASE}/admin/pos`)
        await page.waitForSelector('.product-tile', { timeout: 15_000 })

        // The image area of the tiles now renders the shared Surrogate illustration.
        await expect(page.locator('.product-tile .surrogate').first()).toBeVisible()
    })
})
