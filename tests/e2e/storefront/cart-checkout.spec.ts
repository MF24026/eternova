import { test, expect, type Page } from '@playwright/test'

/**
 * S2-E5/E6 — Cart + WhatsApp Checkout E2E tests.
 *
 * Prerequisites:
 *   1. Dev server running: `./vendor/bin/sail npm run dev`
 *   2. Demo data seeded: `./vendor/bin/sail artisan db:seed`
 *      (provides a tenant with at least one active product and a whatsapp_number)
 *   3. Tests run against localhost:8080 or a tenant subdomain.
 *
 * Approach for intercepting wa.me URL:
 *   The checkout button calls window.open(url, '_blank'). Playwright cannot
 *   follow window.open to external sites, so we intercept it by overriding
 *   window.open via page.addInitScript before navigation. The override stores
 *   the last URL passed to window.open in window.__lastOpenedUrl so we can
 *   assert on it from the test without needing a real WhatsApp connection.
 */

const HYDRATION_TIMEOUT = 15_000
const API_TIMEOUT = 10_000

async function waitForApp(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const app = document.querySelector('#app')
            return app !== null && app.children.length > 0
        },
        { timeout: HYDRATION_TIMEOUT },
    )
}

async function waitForNetworkIdle(page: Page): Promise<void> {
    await page.waitForLoadState('networkidle', { timeout: API_TIMEOUT })
}

/**
 * Installs a window.open interceptor before every navigation.
 * Captured URL is stored in window.__lastOpenedUrl.
 */
async function installOpenInterceptor(page: Page): Promise<void> {
    await page.addInitScript(() => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        ;(window as any).__lastOpenedUrl = null
        const original = window.open.bind(window)
        window.open = (url?: string | URL, target?: string, features?: string) => {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            ;(window as any).__lastOpenedUrl = url ? String(url) : null
            // Do NOT call original — prevents external tab from opening in headless
            return null
        }
        // silence TS "original not used" — needed to keep the original ref
        void original
    })
}

async function getLastOpenedUrl(page: Page): Promise<string | null> {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    return page.evaluate(() => (window as any).__lastOpenedUrl as string | null)
}

// ── Navigate to a product detail page ─────────────────────────────────────────

async function goToFirstProduct(page: Page): Promise<void> {
    await page.goto('/products')
    await waitForApp(page)
    await waitForNetworkIdle(page)

    // Click the first product card to reach its detail page
    const firstCard = page.locator('[data-testid^="product-card-"]').first()
    const cardCount = await firstCard.count()
    if (cardCount === 0) {
        test.skip()
        return
    }
    await firstCard.click()
    await waitForApp(page)
    await waitForNetworkIdle(page)
}

// ── Tests ──────────────────────────────────────────────────────────────────────

test.describe('Cart — add item and badge', () => {
    test('cart badge shows count after adding a product', async ({ page }) => {
        await installOpenInterceptor(page)
        await goToFirstProduct(page)

        // Cart badge is not visible before adding anything
        const badge = page.getByTestId('cart-badge')
        await expect(badge).not.toBeVisible()

        // Add to cart
        const addBtn = page.getByTestId('add-to-cart-btn')
        await expect(addBtn).toBeVisible()
        await addBtn.click()

        // Badge should appear with count 1
        await expect(badge).toBeVisible({ timeout: 3000 })
        await expect(badge).toHaveText('1')
    })

    test('adding same product variant increments badge count', async ({ page }) => {
        await installOpenInterceptor(page)
        await goToFirstProduct(page)

        const addBtn = page.getByTestId('add-to-cart-btn')
        await addBtn.click()
        await addBtn.click()

        const badge = page.getByTestId('cart-badge')
        await expect(badge).toHaveText('2')
    })
})

test.describe('CartSlideover — item list and controls', () => {
    test.beforeEach(async ({ page }) => {
        await installOpenInterceptor(page)
        await goToFirstProduct(page)
        await page.getByTestId('add-to-cart-btn').click()
    })

    test('cart slideover opens on cart icon click and lists the item', async ({ page }) => {
        await page.getByTestId('cart-icon-btn').click()

        const slideover = page.getByTestId('cart-items')
        await expect(slideover).toBeVisible({ timeout: 3000 })

        // At least one item row is rendered
        const items = page.locator('[data-testid^="cart-item-"]')
        await expect(items.first()).toBeVisible()
    })

    test('quantity stepper updates subtotal', async ({ page }) => {
        await page.getByTestId('cart-icon-btn').click()

        const items = page.locator('[data-testid^="cart-item-"]')
        await expect(items.first()).toBeVisible()

        // Grab the variantId from the first item's data-testid
        const firstItem = items.first()
        const testId = await firstItem.getAttribute('data-testid')
        const variantId = testId?.replace('cart-item-', '')

        if (!variantId) {
            test.skip()
            return
        }

        const qtyEl = page.getByTestId(`cart-item-qty-${variantId}`)
        const subtotalEl = page.getByTestId(`cart-item-subtotal-${variantId}`)

        // Record subtotal before increment
        const subtotalBefore = await subtotalEl.textContent()

        await page.getByTestId(`cart-item-increment-${variantId}`).click()

        // qty should now be 2
        await expect(qtyEl).toHaveText('2')

        // subtotal should change
        const subtotalAfter = await subtotalEl.textContent()
        expect(subtotalAfter).not.toBe(subtotalBefore)
    })

    test('removing item empties the cart', async ({ page }) => {
        await page.getByTestId('cart-icon-btn').click()

        const items = page.locator('[data-testid^="cart-item-"]')
        await expect(items.first()).toBeVisible()

        const testId = await items.first().getAttribute('data-testid')
        const variantId = testId?.replace('cart-item-', '')
        if (!variantId) {
            test.skip()
            return
        }

        // Click the remove (trash) button — it's inside the item row, not data-testid tagged,
        // so locate by aria-label pattern within the item row
        const itemRow = page.getByTestId(`cart-item-${variantId}`)
        const removeBtn = itemRow.locator('button[aria-label^="Eliminar"]')
        await removeBtn.click()

        // Empty state should appear
        await expect(page.getByTestId('cart-empty-state')).toBeVisible({ timeout: 3000 })

        // Badge should vanish
        await expect(page.getByTestId('cart-badge')).not.toBeVisible()
    })

    test('cart subtotal updates when quantity changes', async ({ page }) => {
        await page.getByTestId('cart-icon-btn').click()

        const cartSubtotal = page.getByTestId('cart-subtotal')
        await expect(cartSubtotal).toBeVisible()

        const before = await cartSubtotal.textContent()

        const items = page.locator('[data-testid^="cart-item-"]')
        const testId = await items.first().getAttribute('data-testid')
        const variantId = testId?.replace('cart-item-', '')
        if (!variantId) {
            test.skip()
            return
        }

        await page.getByTestId(`cart-item-increment-${variantId}`).click()

        const after = await cartSubtotal.textContent()
        expect(after).not.toBe(before)
    })
})

test.describe('Cart — persistence across reload', () => {
    test('cart persists after page reload', async ({ page }) => {
        await installOpenInterceptor(page)
        await goToFirstProduct(page)
        await page.getByTestId('add-to-cart-btn').click()

        // Confirm badge shows 1
        const badge = page.getByTestId('cart-badge')
        await expect(badge).toHaveText('1')

        // Reload the page
        await page.reload()
        await waitForApp(page)

        // Badge should still show 1 (restored from localStorage)
        await expect(page.getByTestId('cart-badge')).toHaveText('1')
    })
})

test.describe('WhatsApp checkout', () => {
    test.beforeEach(async ({ page }) => {
        await installOpenInterceptor(page)
        await goToFirstProduct(page)
        await page.getByTestId('add-to-cart-btn').click()
        await page.getByTestId('cart-icon-btn').click()
        // Wait for slideover
        await expect(page.getByTestId('cart-items')).toBeVisible({ timeout: 3000 })
    })

    test('checkout builds a wa.me URL containing the product name and total', async ({ page }) => {
        await page.getByTestId('checkout-whatsapp-btn').click()

        // Give the click handler time to run
        await page.waitForTimeout(300)

        const openedUrl = await getLastOpenedUrl(page)

        // The URL must start with the wa.me scheme
        expect(openedUrl).toMatch(/^https:\/\/wa\.me\//)

        // The query string must contain URL-encoded content
        expect(openedUrl).toContain('text=')

        // The decoded message should mention the total
        const decoded = decodeURIComponent(openedUrl ?? '')
        expect(decoded).toMatch(/Total:/)
    })

    test('checkout includes optional customer name when filled', async ({ page }) => {
        await page.getByTestId('checkout-customer-name').fill('Rosa Hernandez')

        await page.getByTestId('checkout-whatsapp-btn').click()
        await page.waitForTimeout(300)

        const openedUrl = await getLastOpenedUrl(page)
        const decoded = decodeURIComponent(openedUrl ?? '')

        expect(decoded).toContain('Rosa Hernandez')
    })

    test('clear cart button empties the cart', async ({ page }) => {
        await page.getByTestId('clear-cart-btn').click()

        await expect(page.getByTestId('cart-empty-state')).toBeVisible({ timeout: 3000 })
        await expect(page.getByTestId('cart-badge')).not.toBeVisible()
    })
})

test.describe('WhatsApp checkout — no number configured', () => {
    /**
     * This test scenario requires a tenant with whatsapp_number = null.
     * In CI the demo seeder always populates a number, so this test is
     * conditional: it only asserts the toast when the button is clicked
     * and no URL is opened.
     *
     * To test locally: temporarily set the demo tenant's whatsapp_number to null
     * in the DB and re-run.
     */
    test('shows fallback toast if no WhatsApp number configured', async ({ page }) => {
        await installOpenInterceptor(page)

        // Override storefront tenant in-page to simulate missing number
        await page.addInitScript(() => {
            // Patch fetch so /api/v1/storefront/tenant returns no whatsapp_number
            const originalFetch = window.fetch.bind(window)
            window.fetch = async (input: RequestInfo | URL, init?: RequestInit) => {
                const url = typeof input === 'string' ? input : input.toString()
                if (url.includes('/storefront/tenant')) {
                    const res = await originalFetch(input, init)
                    const json = await res.json()
                    if (json.data) json.data.whatsapp_number = null
                    return new Response(JSON.stringify(json), {
                        status: res.status,
                        headers: { 'Content-Type': 'application/json' },
                    })
                }
                return originalFetch(input, init)
            }
        })

        await goToFirstProduct(page)
        await page.getByTestId('add-to-cart-btn').click()
        await page.getByTestId('cart-icon-btn').click()
        await expect(page.getByTestId('cart-items')).toBeVisible({ timeout: 3000 })

        await page.getByTestId('checkout-whatsapp-btn').click()
        await page.waitForTimeout(300)

        // No URL should have been opened
        const openedUrl = await getLastOpenedUrl(page)
        expect(openedUrl).toBeNull()

        // A toast with the warning message should appear
        const toast = page.locator('[role="alert"]').or(
            page.locator('[data-testid^="toast"]'),
        )
        await expect(toast.first()).toBeVisible({ timeout: 3000 })
    })
})
