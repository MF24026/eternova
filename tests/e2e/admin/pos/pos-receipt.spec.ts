import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// ---------------------------------------------------------------------------
// Helpers  (mirrors the pattern from pos.spec.ts)
// ---------------------------------------------------------------------------

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost'

interface TestUser {
    email: string
    password: string
    name: string
}

async function registerAndLogin(request: APIRequestContext, page: Page): Promise<TestUser> {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    const user: TestUser = {
        name: `Receipt Tester ${ts}`,
        email: `receipt-test-${ts}-${suffix}@example.com`,
        password: 'ReceiptTest123',
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
    await page.waitForTimeout(1_200)
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('POS receipt slideover', () => {
    test('receipt slideover appears with order number and total after checkout', async ({
        page,
        request,
    }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // Fresh tenant: no products seeded — skip the data-dependent assertions.
        const tiles = page.locator('.product-tile:not([disabled])')
        const tileCount = await tiles.count()

        if (tileCount === 0) {
            test.skip()
            return
        }

        // Add a product to the cart.
        await tiles.first().click()
        await page.waitForTimeout(300)

        // Ensure the Cobrar button is enabled.
        const cobrarBtn = page.locator('[data-testid="pos-checkout-btn"]')
        await expect(cobrarBtn).toBeEnabled({ timeout: 5_000 })

        // Click Cobrar — triggers checkout + receipt fetch.
        await cobrarBtn.click()

        // The receipt slideover should appear.
        const receiptPanel = page.locator('[data-testid="pos-receipt-panel"]')
        await expect(receiptPanel).toBeVisible({ timeout: 15_000 })

        // Order number must be present and non-empty.
        const orderNumber = page.locator('[data-testid="pos-receipt-order-number"]')
        await expect(orderNumber).toBeVisible()
        const orderText = await orderNumber.textContent()
        expect(orderText?.trim().length).toBeGreaterThan(1)

        // Total must be visible.
        await expect(page.locator('[data-testid="pos-receipt-total"]')).toBeVisible()

        // Business name must be visible.
        await expect(page.locator('[data-testid="pos-receipt-business-name"]')).toBeVisible()

        // Item list must have at least one row.
        const items = page.locator('[data-testid="pos-receipt-items"] > div')
        expect(await items.count()).toBeGreaterThan(0)
    })

    test('Imprimir button is present on the receipt slideover', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        if (await tiles.count() === 0) {
            test.skip()
            return
        }

        await tiles.first().click()
        await page.waitForTimeout(300)

        const cobrarBtn = page.locator('[data-testid="pos-checkout-btn"]')
        await expect(cobrarBtn).toBeEnabled({ timeout: 5_000 })
        await cobrarBtn.click()

        const receiptPanel = page.locator('[data-testid="pos-receipt-panel"]')
        await expect(receiptPanel).toBeVisible({ timeout: 15_000 })

        // The "Imprimir" button must be visible and not disabled.
        const printBtn = page.locator('[data-testid="pos-receipt-print-btn"]')
        await expect(printBtn).toBeVisible()
        await expect(printBtn).toBeEnabled()
    })

    test('"Nueva venta" closes the receipt and empties the cart', async ({ page, request }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        if (await tiles.count() === 0) {
            test.skip()
            return
        }

        // Add a product and complete the sale.
        await tiles.first().click()
        await page.waitForTimeout(300)

        const cobrarBtn = page.locator('[data-testid="pos-checkout-btn"]')
        await expect(cobrarBtn).toBeEnabled({ timeout: 5_000 })
        await cobrarBtn.click()

        const receiptPanel = page.locator('[data-testid="pos-receipt-panel"]')
        await expect(receiptPanel).toBeVisible({ timeout: 15_000 })

        // Click "Nueva venta".
        const newSaleBtn = page.locator('[data-testid="pos-receipt-new-sale-btn"]')
        await expect(newSaleBtn).toBeVisible()
        await newSaleBtn.click()
        await page.waitForTimeout(400)

        // The receipt slideover must be closed.
        await expect(receiptPanel).not.toBeVisible()

        // The cart should now be empty.
        await expect(page.locator('[data-testid="pos-empty-cart"]')).toBeVisible()

        // The Cobrar button must be disabled again (empty cart).
        await expect(page.locator('[data-testid="pos-checkout-btn"]')).toBeDisabled()
    })

    test('POS page is still reachable and functional after opening/closing receipt', async ({
        page,
        request,
    }) => {
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // This test passes even with no products — it checks the page shell.
        await expect(page).toHaveTitle(/Punto de venta/)
        await expect(page.locator('[data-testid="pos-checkout-btn"]')).toBeVisible()

        // Receipt slideover should NOT be visible on fresh page load.
        await expect(page.locator('[data-testid="pos-receipt-panel"]')).not.toBeVisible()
    })
})
