import { test, expect, type Page } from '@playwright/test'

/**
 * POS checkout overlay — the Cobrar button opens a full-screen overlay to pick a
 * payment method and, for cash, enter the amount received and see the change.
 * Confirming sends amount_received_cents; the receipt shows Recibí / Vuelto.
 *
 * Seeded rosa-eterna tenant on its subdomain (only Chromium resolves it).
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

/** Add one single-variant product so the tile adds instantly (no variant overlay). */
async function addOneProduct(page: Page): Promise<void> {
    const name = await page.evaluate(async () => {
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
        const products = (await r.json()).data as Array<{ name: string; variants: Array<{ available_quantity: number }> }>
        return products.find((p) => p.variants.length === 1 && p.variants[0].available_quantity > 0)?.name ?? ''
    })
    expect(name, 'a seeded single-variant product').not.toBe('')

    await page.goto(`${TENANT_BASE}/admin/pos`)
    await page.waitForSelector('.product-tile', { timeout: 15_000 })
    await page.getByRole('button', { name: `Agregar ${name} al carrito` }).first().click()
}

test.describe('POS checkout overlay (seeded tenant)', () => {
    test.beforeEach(async ({ }, testInfo) => {
        test.skip(testInfo.project.name === 'chromium-mobile', 'desktop flow; mobile in QA')
    })

    test('cash checkout captures change and shows it on the receipt', async ({ page }) => {
        await login(page)
        await addOneProduct(page)

        await page.locator('[data-testid="pos-checkout-btn"]').first().click()
        const overlay = page.locator('[data-testid="pos-checkout-overlay"]')
        await expect(overlay).toBeVisible()

        // Cash is the default method. With nothing received yet the total is not
        // covered, so confirm is disabled and change reads as a dash.
        const confirm = overlay.locator('[data-testid="pos-checkout-confirm"]')
        await expect(confirm).toBeDisabled()
        await expect(overlay.locator('[data-testid="pos-change"]')).toHaveText('—')

        // Enter $999.00 via the keypad — more than any single seeded product.
        for (const key of ['9', '9', '9']) {
            await overlay.locator(`[data-testid="pos-key-${key}"]`).click()
        }
        await expect(overlay.locator('[data-testid="pos-received"]')).toHaveText('$999.00')
        await expect(overlay.locator('[data-testid="pos-change"]')).not.toHaveText('—')
        await expect(confirm).toBeEnabled()

        await confirm.click()

        // Overlay closes; the receipt shows Recibí and Vuelto.
        await expect(overlay).toBeHidden()
        await expect(page.locator('[data-testid="pos-receipt-received"]')).toHaveText('$999.00')
        await expect(page.locator('[data-testid="pos-receipt-change"]')).toBeVisible()
    })

    test('card payment skips the keypad and confirms directly', async ({ page }) => {
        await login(page)
        await addOneProduct(page)

        await page.locator('[data-testid="pos-checkout-btn"]').first().click()
        const overlay = page.locator('[data-testid="pos-checkout-overlay"]')
        await expect(overlay).toBeVisible()

        await overlay.locator('[data-testid="pos-pay-card"]').click()

        // No keypad for card; confirm is enabled immediately.
        await expect(overlay.locator('[data-testid="pos-key-5"]')).toHaveCount(0)
        await expect(overlay.locator('[data-testid="pos-checkout-confirm"]')).toBeEnabled()
    })
})
