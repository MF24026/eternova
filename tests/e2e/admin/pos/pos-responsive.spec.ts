import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost'

// Breakpoints under test
const VIEWPORTS = {
    mobile: { width: 375, height: 812 },
    tablet: { width: 768, height: 1024 },
    desktop: { width: 1280, height: 800 },
} as const

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function registerAndLogin(request: APIRequestContext, page: Page): Promise<void> {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    const user = {
        name: `Responsive POS Tester ${ts}`,
        email: `pos-resp-${ts}-${suffix}@example.com`,
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
    // Allow product API call to resolve or 404.
    await page.waitForTimeout(1_200)
}

/**
 * Returns true if the document has horizontal scroll.
 * Allows 1px tolerance for sub-pixel rendering.
 */
async function hasHorizontalScroll(page: Page): Promise<boolean> {
    return page.evaluate(() => {
        return document.documentElement.scrollWidth > window.innerWidth + 1
    })
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('POS responsive — no horizontal scroll', () => {
    // One auth setup shared across viewport sub-tests within the describe block.
    // Each test registers its own user to stay isolated.

    test('desktop (1280x800) — no horizontal overflow', async ({ page, request }) => {
        await page.setViewportSize(VIEWPORTS.desktop)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const scrolls = await hasHorizontalScroll(page)
        expect(scrolls, 'Desktop should not overflow horizontally').toBe(false)
    })

    test('tablet (768x1024) — no horizontal overflow', async ({ page, request }) => {
        await page.setViewportSize(VIEWPORTS.tablet)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const scrolls = await hasHorizontalScroll(page)
        expect(scrolls, 'Tablet should not overflow horizontally').toBe(false)
    })

    test('mobile (375x812) — no horizontal overflow', async ({ page, request }) => {
        await page.setViewportSize(VIEWPORTS.mobile)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const scrolls = await hasHorizontalScroll(page)
        expect(scrolls, 'Mobile should not overflow horizontally').toBe(false)
    })
})

test.describe('POS responsive — desktop split-view', () => {
    test('desktop (1280x800) — inline cart panel is visible', async ({ page, request }) => {
        await page.setViewportSize(VIEWPORTS.desktop)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // The desktop cart panel is wrapped in a div.hidden.lg:block
        const desktopCart = page.locator('[data-testid="pos-cart-panel-desktop"]')
        await expect(desktopCart).toBeVisible()

        // The floating bottom bar must NOT be in the DOM on desktop with empty cart
        // (v-if="lineCount > 0" removes it from the DOM entirely)
        const bottomBar = page.locator('[data-testid="pos-cart-bottom-bar"]')
        await expect(bottomBar).not.toBeAttached()
    })

    test('desktop — "Venta en curso" label visible in inline cart', async ({ page, request }) => {
        await page.setViewportSize(VIEWPORTS.desktop)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        await expect(page.getByText('Venta en curso')).toBeVisible()
    })

    test('desktop — Cobrar button is present and disabled on empty cart', async ({ page, request }) => {
        await page.setViewportSize(VIEWPORTS.desktop)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const cobrarBtn = page.locator('[data-testid="pos-checkout-btn"]').first()
        await expect(cobrarBtn).toBeVisible()
        await expect(cobrarBtn).toBeDisabled()
    })
})

test.describe('POS responsive — mobile bottom-sheet', () => {
    test('mobile (375x812) — inline cart column is not displayed, bottom bar absent on empty cart', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(VIEWPORTS.mobile)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // Desktop cart column uses "hidden lg:block" — at mobile the computed
        // display should be "none". Use getComputedStyle to verify rather than
        // toBeVisible(), which can have subtleties with Tailwind responsive classes.
        const cartPanelDisplay = await page.evaluate(() => {
            const el = document.querySelector('[data-testid="pos-cart-panel-desktop"]')
            if (!el) return 'missing'
            return window.getComputedStyle(el).display
        })
        expect(cartPanelDisplay, 'Desktop cart column should be display:none on mobile').toBe('none')

        // With an empty cart the floating bar should NOT be rendered (v-if="lineCount > 0")
        const bottomBar = page.locator('[data-testid="pos-cart-bottom-bar"]')
        // The button inside the Transition is absent from the DOM when lineCount === 0
        await expect(bottomBar).not.toBeAttached()
    })

    test('mobile — adding a product shows the floating total bar', async ({ page, request }) => {
        await page.setViewportSize(VIEWPORTS.mobile)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        const tileCount = await tiles.count()

        if (tileCount === 0) {
            // No seeded products for a fresh tenant — skip data-dependent assertion.
            test.skip()
            return
        }

        await tiles.first().click()
        await page.waitForTimeout(400)

        // Floating bar should now be visible
        const bottomBar = page.locator('[data-testid="pos-cart-bottom-bar"]')
        await expect(bottomBar).toBeVisible()

        // Bar shows a total (non-zero currency string)
        const barText = await bottomBar.textContent()
        expect(barText).toBeTruthy()
    })

    test('mobile — tapping the bottom bar opens the cart sheet with Cobrar button', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(VIEWPORTS.mobile)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        const tileCount = await tiles.count()

        if (tileCount === 0) {
            test.skip()
            return
        }

        await tiles.first().click()
        await page.waitForTimeout(400)

        // Open the cart sheet
        const bottomBar = page.locator('[data-testid="pos-cart-bottom-bar"]')
        await expect(bottomBar).toBeVisible()
        await bottomBar.click()
        await page.waitForTimeout(500)

        // Cobrar button must be visible inside the sheet
        const cobrarBtn = page.locator('[data-testid="pos-checkout-btn"]').first()
        await expect(cobrarBtn).toBeVisible()
        await expect(cobrarBtn).toBeEnabled()
    })
})

test.describe('POS responsive — customer selector', () => {
    test('desktop — customer selector button opens the selector panel', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(VIEWPORTS.desktop)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const customerBtn = page.locator('[data-testid="pos-customer-btn"]').first()
        await expect(customerBtn).toBeVisible()
        await customerBtn.click()
        await page.waitForTimeout(400)

        // The customer selector slideover must open
        const selector = page.locator('[data-testid="pos-customer-selector"]')
        await expect(selector).toBeVisible()

        // Walk-in option must be present
        const walkin = page.locator('[data-testid="pos-customer-walkin"]')
        await expect(walkin).toBeVisible()
    })

    test('mobile — customer selector opens from within the cart sheet', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(VIEWPORTS.mobile)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        const tiles = page.locator('.product-tile:not([disabled])')
        if (await tiles.count() === 0) {
            // Without products the cart sheet is inaccessible — skip.
            test.skip()
            return
        }

        // Add a product to show the cart bar
        await tiles.first().click()
        await page.waitForTimeout(400)

        // Open cart sheet
        await page.locator('[data-testid="pos-cart-bottom-bar"]').click()
        await page.waitForTimeout(500)

        // The customer button is inside the cart panel rendered in the sheet
        const customerBtn = page.locator('[data-testid="pos-customer-btn"]').first()
        await expect(customerBtn).toBeVisible()
        await customerBtn.click()
        await page.waitForTimeout(400)

        // Customer selector should be open (it teleports to body)
        const selector = page.locator('[data-testid="pos-customer-selector"]')
        await expect(selector).toBeVisible()
    })

    test('customer selector search input is reachable and focusable', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(VIEWPORTS.desktop)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        await page.locator('[data-testid="pos-customer-btn"]').first().click()
        await page.waitForTimeout(400)

        const searchInput = page.locator('[data-testid="pos-customer-search-input"]')
        await expect(searchInput).toBeVisible()
        await searchInput.click()
        // Just verify it can receive input without crashing
        await searchInput.type('test')
        await page.waitForTimeout(500)
        // No crash — still on POS page
        expect(page.url()).toContain('/admin/pos')
    })
})

test.describe('POS responsive — tablet layout', () => {
    test('tablet (768x1024) — product grid renders without horizontal scroll', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(VIEWPORTS.tablet)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // Search input (product grid) must be visible
        await expect(
            page.locator('input[placeholder*="Buscar producto"]').first(),
        ).toBeVisible()

        const scrolls = await hasHorizontalScroll(page)
        expect(scrolls).toBe(false)
    })

    test('tablet (768x1024) — desktop inline cart is display:none (uses mobile pattern)', async ({
        page,
        request,
    }) => {
        await page.setViewportSize(VIEWPORTS.tablet)
        await registerAndLogin(request, page)
        await navigateToPOS(page)

        // At 768px the desktop cart column (hidden lg:block) — "lg" is 1024px, so at
        // 768px the computed display should be "none".
        const cartPanelDisplay = await page.evaluate(() => {
            const el = document.querySelector('[data-testid="pos-cart-panel-desktop"]')
            if (!el) return 'missing'
            return window.getComputedStyle(el).display
        })
        expect(cartPanelDisplay, 'Desktop cart column should be display:none at tablet width').toBe('none')
    })
})
