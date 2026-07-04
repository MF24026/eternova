import { test, expect, type Page, type APIRequestContext } from '@playwright/test'
import { BASE_URL } from '../../support/env'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

interface TestUser {
    email: string
    password: string
    name: string
}

async function registerAndLogin(request: APIRequestContext, page: Page): Promise<TestUser> {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    const user: TestUser = {
        name: `POS Tester ${ts}`,
        email: `pos-test-${ts}-${suffix}@example.com`,
        // Must satisfy: min 8 chars, mixed case, contains numbers.
        password: 'PosTest123',
    }

    const res = await request.post(`${BASE_URL}/api/v1/auth/register`, {
        data: user,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })

    if (!res.ok()) {
        const body = await res.text()
        throw new Error(`register failed ${res.status()}: ${body}`)
    }

    await page.goto(`${BASE_URL}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', user.email)
    await page.fill('input[type="password"]', user.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })

    return user
}

async function waitForApp(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const el = document.querySelector('#app')
            return el !== null && el.children.length > 0
        },
        { timeout: 15_000 },
    )
}

async function navigateToPOS(page: Page): Promise<void> {
    await page.goto(`${BASE_URL}/admin/pos`)
    await waitForApp(page)
    // Give the products API call time to resolve (or 404 with empty list).
    await page.waitForTimeout(1_200)
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('POS page — happy path', () => {
    test('POS page loads and shows the split-view layout', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        await expect(page).toHaveTitle(/Punto de venta/)

        // Left panel: search input must be visible.
        await expect(
            page.locator('input[placeholder*="Buscar producto"]').first(),
        ).toBeVisible()

        // Right panel: "Venta en curso" label must be visible.
        await expect(page.getByText('Venta en curso')).toBeVisible()

        // Empty cart message when cart starts empty.
        await expect(page.locator('[data-testid="pos-empty-cart"]')).toBeVisible()

        // "Cobrar" button must exist and be disabled while cart is empty.
        const cobrarBtn = page.locator('[data-testid="pos-checkout-btn"]')
        await expect(cobrarBtn).toBeVisible()
        await expect(cobrarBtn).toBeDisabled()
    })

    test('products load or empty state shows without crashing', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // Either product tiles or the empty-state text — never a crash.
        const tiles = page.locator('.product-tile')
        const emptyState = page.getByText('Sin productos', { exact: false })

        const tileCount = await tiles.count()
        const emptyVisible = await emptyState.isVisible().catch(() => false)

        expect(tileCount > 0 || emptyVisible).toBe(true)
    })

    test('adding a product enables the Cobrar button and updates the cart', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        const tileCount = await tiles.count()

        if (tileCount === 0) {
            // No seeded products for this fresh user — skip the interaction assertions.
            test.skip()
            return
        }

        // Click the first non-disabled product tile.
        await tiles.first().click()
        await page.waitForTimeout(300)

        // Empty cart message should be gone.
        await expect(page.locator('[data-testid="pos-empty-cart"]')).not.toBeVisible()

        // Cobrar button should now be enabled.
        await expect(page.locator('[data-testid="pos-checkout-btn"]')).toBeEnabled()
    })

    test('qty stepper increments and decrements correctly', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        if (await tiles.count() === 0) {
            test.skip()
            return
        }

        await tiles.first().click()
        await page.waitForTimeout(300)

        // The quantity display starts at 1.
        const qtyDisplay = page.locator('.tabular-nums').filter({ hasText: /^\d+$/ }).first()
        await expect(qtyDisplay).toHaveText('1')

        // Click the "+" button (aria-label contains "Aumentar cantidad").
        const plusBtn = page.getByRole('button', { name: /Aumentar cantidad/ }).first()
        await plusBtn.click()
        await page.waitForTimeout(200)
        await expect(qtyDisplay).toHaveText('2')

        // Click the "−" button.
        const minusBtn = page.getByRole('button', { name: /Reducir cantidad/ }).first()
        await minusBtn.click()
        await page.waitForTimeout(200)
        await expect(qtyDisplay).toHaveText('1')
    })

    test('"venta en curso" persists across a page reload (localStorage)', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        if (await tiles.count() === 0) {
            test.skip()
            return
        }

        // Add a product to the cart.
        await tiles.first().click()
        await page.waitForTimeout(300)

        // Capture the product name from the first cart line.
        const cartLine = page.locator('.pos-cart-line, [aria-label="Productos en el carrito"] > div').first()
        const productNameBefore = await cartLine.textContent().catch(() => '')

        // Reload the page — this clears Vue state but localStorage should restore the cart.
        await page.reload()
        await waitForApp(page)
        await page.waitForTimeout(1_000)

        // The "Cobrar" button should still be enabled (cart was restored).
        await expect(page.locator('[data-testid="pos-checkout-btn"]')).toBeEnabled({ timeout: 8_000 })

        // The empty cart message should NOT appear.
        await expect(page.locator('[data-testid="pos-empty-cart"]')).not.toBeVisible()

        // The product name should still be visible in the cart.
        if (productNameBefore && productNameBefore.length > 2) {
            const trimmedName = productNameBefore.trim().slice(0, 20)
            if (trimmedName) {
                await expect(
                    page.getByText(trimmedName, { exact: false }).first(),
                ).toBeVisible({ timeout: 5_000 })
            }
        }
    })

    test('payment method selector buttons are all reachable', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        await expect(page.getByRole('button', { name: /Efectivo/ })).toBeVisible()
        await expect(page.getByRole('button', { name: /Tarjeta/ })).toBeVisible()
        await expect(page.getByRole('button', { name: /Transferencia/ })).toBeVisible()

        // Switch to "Tarjeta" and verify aria-pressed state.
        await page.getByRole('button', { name: /Tarjeta/ }).click()
        await expect(page.getByRole('button', { name: /Tarjeta/ })).toHaveAttribute('aria-pressed', 'true')
        await expect(page.getByRole('button', { name: /Efectivo/ })).toHaveAttribute('aria-pressed', 'false')
    })

    test('category tabs filter the product grid without crashing', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // The "Todo" tab must be present and active by default.
        const todoTab = page.getByRole('tab', { name: 'Todo' })
        await expect(todoTab).toBeVisible()
        await expect(todoTab).toHaveAttribute('aria-selected', 'true')

        // Click any other tab if present — should not crash.
        const allTabs = page.getByRole('tab')
        const tabCount = await allTabs.count()

        if (tabCount > 1) {
            await allTabs.nth(1).click()
            await page.waitForTimeout(500)
            // Page should still show either products or the empty state — no crash.
            const tiles = page.locator('.product-tile')
            const empty = page.getByText('Sin productos', { exact: false })
            const visible = (await tiles.count()) > 0 || (await empty.isVisible().catch(() => false))
            expect(visible).toBe(true)
        }
    })

    test('Cobrar with empty cart is a no-op (button stays disabled)', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const cobrarBtn = page.locator('[data-testid="pos-checkout-btn"]')
        await expect(cobrarBtn).toBeDisabled()

        // Clicking a disabled button should not trigger checkout or crash.
        await cobrarBtn.dispatchEvent('click')
        await page.waitForTimeout(400)

        // Still on the POS page.
        expect(page.url()).toContain('/admin/pos')
    })
})
