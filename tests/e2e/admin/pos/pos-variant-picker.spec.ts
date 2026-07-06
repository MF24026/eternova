import { test, expect, type Page, type Locator } from '@playwright/test'

/**
 * POS variant picker overlay — tapping a multi-variant product opens a
 * full-screen overlay to choose variant quantities; single-variant products add
 * instantly. Runs against the seeded rosa-eterna tenant on its subdomain (only
 * Chromium resolves *.eternova.localhost).
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('#password', OWNER.password)
    await page.click('button.auth-submit')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function openPos(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/admin/pos`)
    await page.waitForSelector('.product-tile', { timeout: 15_000 })
}

interface ApiProduct {
    name: string
    variants: Array<{ id: number; available_quantity: number }>
}

/** Load POS products via an in-page fetch (Node cannot resolve the subdomain). */
async function loadProducts(page: Page): Promise<ApiProduct[]> {
    return page.evaluate(async () => {
        const b = await fetch('/api/v1/branches', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        const branches = (await b.json()).data as Array<{ id: string; is_main: boolean }>
        const branchId = (branches.find((x) => x.is_main) ?? branches[0]).id
        const r = await fetch(`/api/v1/pos/products?branch_id=${branchId}&per_page=100`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        return (await r.json()).data
    })
}

/** Cart lines in the desktop cart panel, counted via their remove buttons. */
function cartLines(page: Page): Locator {
    return page
        .locator('[data-testid="pos-cart-panel-desktop"]')
        .getByRole('button', { name: /Eliminar .+ del carrito/ })
}

test.describe('POS variant picker (seeded tenant)', () => {
    test.beforeEach(async ({ }, testInfo) => {
        test.skip(testInfo.project.name === 'chromium-mobile', 'covered on desktop; mobile in QA')
    })

    test('multi-variant product opens the overlay and adds the chosen variants', async ({ page }) => {
        await login(page)
        const products = await loadProducts(page)
        const multi = products.find(
            (p) => p.variants.filter((v) => v.available_quantity > 0).length >= 2,
        )
        expect(multi, 'a seeded product with 2+ in-stock variants').toBeTruthy()

        await openPos(page)
        await page.getByRole('button', { name: `Agregar ${multi!.name} al carrito` }).first().click()

        const overlay = page.locator('[data-testid="pos-variant-overlay"]')
        await expect(overlay).toBeVisible()

        const inStock = multi!.variants.filter((v) => v.available_quantity > 0).slice(0, 2)
        for (const v of inStock) {
            await overlay.locator(`[data-testid="pos-variant-inc-${v.id}"]`).click()
            await expect(overlay.locator(`[data-testid="pos-variant-qty-${v.id}"]`)).toHaveText('1')
        }

        // Total is no longer $0 and the CTA is enabled.
        await expect(overlay.locator('[data-testid="pos-variant-total"]')).not.toHaveText('$0.00')
        const confirm = overlay.locator('[data-testid="pos-variant-confirm"]')
        await expect(confirm).toBeEnabled()
        await confirm.click()

        // Overlay closes and the cart shows the two chosen variant lines.
        await expect(overlay).toBeHidden()
        await expect(cartLines(page)).toHaveCount(2)
    })

    test('single-variant product adds instantly with no overlay', async ({ page }) => {
        await login(page)
        const products = await loadProducts(page)
        const single = products.find(
            (p) => p.variants.length === 1 && p.variants[0].available_quantity > 0,
        )
        expect(single, 'a seeded single-variant in-stock product').toBeTruthy()

        await openPos(page)
        await page.getByRole('button', { name: `Agregar ${single!.name} al carrito` }).first().click()

        await expect(page.locator('[data-testid="pos-variant-overlay"]')).toHaveCount(0)
        await expect(cartLines(page)).toHaveCount(1)
    })
})
