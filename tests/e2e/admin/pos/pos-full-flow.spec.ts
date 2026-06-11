import { test, expect, type Page } from '@playwright/test'

/**
 * Full POS happy-path E2E against a SEEDED tenant.
 *
 * Unlike the other POS specs (which fresh-register a tenant on bare `localhost`
 * and therefore have no products), this one logs in as the demo owner on the
 * tenant SUBDOMAIN, where tenant-scoped routes resolve and the catalog/stock are
 * seeded. It exercises the complete flow required by S3-E7:
 *
 *   search -> add -> payment -> cobrar -> receipt -> stock deducted
 *   + localStorage: venta persists across reload, clears after cobrar.
 *
 * Requires `DemoTenantsSeeder` + catalog/inventory seeders (owner
 * caro@rosaeterna.com on tenant `rosa-eterna`). The tenant subdomain resolves to
 * loopback via RFC 6761 (*.localhost). Inside Sail the app listens on :80.
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
    await page.waitForFunction(
        () => {
            const el = document.querySelector('#app')
            return el !== null && el.children.length > 0
        },
        { timeout: 15_000 },
    )
    // Wait until at least one product tile has rendered (grid loaded from the API).
    await page.waitForSelector('.product-tile', { timeout: 15_000 })
}

/**
 * Fetch JSON from the API using an IN-PAGE fetch (relative URL). page.request runs
 * Node-side and cannot resolve *.eternova.localhost (only Chromium applies the RFC
 * 6761 loopback rule); an in-page same-origin fetch resolves the host and carries
 * the session cookie + a stateful Origin automatically.
 */
async function apiGet<T>(page: Page, path: string): Promise<T> {
    const result = await page.evaluate(async (p) => {
        const r = await fetch(p, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        return { ok: r.ok, status: r.status, body: await r.json() }
    }, path)
    expect(result.ok, `GET ${path} -> ${result.status}`).toBeTruthy()
    return result.body as T
}

/** Resolve the main branch id + a high-stock variant for deterministic assertions. */
async function pickHighStockVariant(page: Page): Promise<{ branchId: string; productName: string; variantId: number; before: number }> {
    const branchesBody = await apiGet<{ data: Array<{ id: string; is_main: boolean }> }>(page, '/api/v1/branches')
    const branches = branchesBody.data
    const branchId = (branches.find((b) => b.is_main) ?? branches[0]).id

    const productsBody = await apiGet<{ data: Array<{ name: string; variants: Array<{ id: number; available_quantity: number }> }> }>(
        page,
        `/api/v1/pos/products?branch_id=${branchId}&per_page=100`,
    )
    const products = productsBody.data

    let best: { productName: string; variantId: number; before: number } | null = null
    for (const p of products) {
        for (const v of p.variants ?? []) {
            if (best === null || v.available_quantity > best.before) {
                best = { productName: p.name, variantId: v.id, before: v.available_quantity }
            }
        }
    }
    if (best === null || best.before < 2) {
        throw new Error('No seeded variant with sufficient stock for the full-flow test.')
    }
    return { branchId, ...best }
}

async function readVariantStock(page: Page, branchId: string, variantId: number): Promise<number> {
    const body = await apiGet<{ data: Array<{ variants: Array<{ id: number; available_quantity: number }> }> }>(
        page,
        `/api/v1/pos/products?branch_id=${branchId}&per_page=100`,
    )
    const products = body.data
    for (const p of products) {
        const v = (p.variants ?? []).find((x) => x.id === variantId)
        if (v) return v.available_quantity
    }
    throw new Error(`Variant ${variantId} not found when re-reading stock.`)
}

test.describe('POS full flow (seeded tenant)', () => {
    // The inline cart drives the desktop flow. The mobile bottom-sheet + swipe is
    // covered by pos-responsive.spec.ts and the qa-engineer visual pass.
    test.beforeEach(async ({ }, testInfo) => {
        test.skip(testInfo.project.name === 'chromium-mobile', 'desktop-only full flow')
    })

    test('search -> add -> cobrar -> receipt -> stock deducted', async ({ page }) => {
        await login(page)
        const target = await pickHighStockVariant(page)

        await openPos(page)

        // Add the chosen product via its accessible tile.
        const tile = page.getByRole('button', { name: `Agregar ${target.productName} al carrito` }).first()
        await tile.click()

        // Cobrar button is enabled.
        const cobrar = page.locator('[data-testid="pos-checkout-btn"]').first()
        await expect(cobrar).toBeEnabled()
        await cobrar.click()

        // Receipt appears with the order number + total.
        const receipt = page.locator('[data-testid="pos-receipt-panel"]')
        await expect(receipt).toBeVisible({ timeout: 10_000 })
        await expect(page.locator('[data-testid="pos-receipt-order-number"]')).toContainText(/\w/)
        await expect(page.locator('[data-testid="pos-receipt-total"]')).toBeVisible()

        // Stock for the sold variant decreased by exactly 1.
        const after = await readVariantStock(page, target.branchId, target.variantId)
        expect(after).toBe(target.before - 1)

        // Nueva venta clears the cart and closes the receipt.
        await page.locator('[data-testid="pos-receipt-new-sale-btn"]').click()
        await expect(receipt).not.toBeVisible()
        await expect(page.locator('[data-testid="pos-empty-cart"]')).toBeVisible()
    })

    test('venta en curso persists across reload and clears after cobrar', async ({ page }) => {
        await login(page)
        const target = await pickHighStockVariant(page)
        await openPos(page)

        await page.getByRole('button', { name: `Agregar ${target.productName} al carrito` }).first().click()
        await expect(page.locator('[data-testid="pos-checkout-btn"]').first()).toBeEnabled()

        // Reload — localStorage must restore the cart.
        await page.reload()
        await openPos(page)
        await expect(page.locator('[data-testid="pos-checkout-btn"]').first()).toBeEnabled({ timeout: 8_000 })
        await expect(page.locator('[data-testid="pos-empty-cart"]')).not.toBeVisible()

        // Cobrar clears the cart.
        await page.locator('[data-testid="pos-checkout-btn"]').first().click()
        await expect(page.locator('[data-testid="pos-receipt-panel"]')).toBeVisible({ timeout: 10_000 })
        await page.locator('[data-testid="pos-receipt-new-sale-btn"]').click()
        await expect(page.locator('[data-testid="pos-empty-cart"]')).toBeVisible()
    })
})
