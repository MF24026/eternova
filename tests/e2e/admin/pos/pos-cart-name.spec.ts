import { test, expect, type Page } from '@playwright/test'

/**
 * U3 (frontend-polish-batch) — POS cart line-item names must be readable.
 *
 * Regression guard: the desktop cart line rendered the product name with a
 * single-line `truncate`, so in the narrow (~380px) cart panel long names were
 * cut to 3-4 characters ("Puls...", "Port...") and the cashier could not tell
 * what was being charged. The fix restructures the line to a two-row layout with
 * the name at full width and a 2-line clamp.
 *
 * We assert the name element is NOT horizontally clipped (scrollWidth <=
 * clientWidth). textContent alone is useless here — CSS truncation never
 * changes textContent, so only the box geometry reveals the clipping.
 *
 * Runs against the seeded rosa-eterna tenant on its subdomain (only Chromium
 * resolves *.eternova.localhost).
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function openPos(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/admin/pos`)
    await page.waitForSelector('.product-tile', { timeout: 15_000 })
}

/** Pick the in-stock product with the LONGEST name — the worst case for truncation. */
async function pickLongestNamedProduct(page: Page): Promise<string> {
    const result = await page.evaluate(async () => {
        const branchesRes = await fetch('/api/v1/branches', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        const branches = (await branchesRes.json()).data as Array<{ id: string; is_main: boolean }>
        const branchId = (branches.find((b) => b.is_main) ?? branches[0]).id

        const prodRes = await fetch(`/api/v1/pos/products?branch_id=${branchId}&per_page=100`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        const products = (await prodRes.json()).data as Array<{
            name: string
            variants: Array<{ available_quantity: number }>
        }>

        let longest = ''
        for (const p of products) {
            const hasStock = (p.variants ?? []).some((v) => v.available_quantity > 0)
            if (hasStock && p.name.length > longest.length) longest = p.name
        }
        return longest
    })
    expect(result.length, 'a seeded in-stock product name').toBeGreaterThan(8)
    return result
}

test.describe('POS cart line name (seeded tenant)', () => {
    test.beforeEach(async ({ }, testInfo) => {
        test.skip(testInfo.project.name === 'chromium-mobile', 'desktop cart panel is desktop-only')
    })

    test('long product name is not horizontally truncated in the cart', async ({ page }) => {
        await login(page)
        const productName = await pickLongestNamedProduct(page)

        await openPos(page)

        await page.getByRole('button', { name: `Agregar ${productName} al carrito` }).first().click()

        // Locate the specific cart line by name (robust to any other items).
        const nameEl = page.locator('[data-testid="pos-cart-line-name"]', { hasText: productName }).first()
        await expect(nameEl).toBeVisible()

        // The full name is in the DOM (truncation never alters textContent)...
        await expect(nameEl).toHaveText(productName)

        // ...and it is NOT horizontally clipped (this is what actually regressed).
        const clipped = await nameEl.evaluate((el) => el.scrollWidth > el.clientWidth + 1)
        expect(clipped, 'cart line name should wrap, not truncate horizontally').toBe(false)
    })
})
