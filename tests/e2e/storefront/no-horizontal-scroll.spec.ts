import { test, expect, type Page } from '@playwright/test'

/**
 * U2 (frontend-polish-batch) — the public storefront must not scroll
 * horizontally.
 *
 * Regression guard: the ambient decorative petals in StorefrontLayout used
 * `position: fixed` with negative offsets (right: -80px), so the root's
 * `overflow-x-clip` could not contain them and the page gained a horizontal
 * scrollbar (scrollWidth 1583 > clientWidth 1425). The fix makes the petals
 * `absolute` inside a `relative` clipping root.
 *
 * Only Chromium resolves *.eternova.localhost (RFC 6761 loopback), so we hit
 * the tenant subdomain directly.
 */

const ROSA_BASE = process.env.STOREFRONT_ROSA_URL ?? 'http://rosa-eterna.eternova.localhost'

/** Wait for the storefront SPA to mount. */
async function waitForStorefront(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const app = document.querySelector('#app')
            return !!app && app.children.length > 0
        },
        { timeout: 20_000 },
    )
}

async function hasHorizontalOverflow(page: Page): Promise<{ scrollWidth: number; clientWidth: number }> {
    return page.evaluate(() => {
        const de = document.documentElement
        return { scrollWidth: de.scrollWidth, clientWidth: de.clientWidth }
    })
}

test.describe('storefront horizontal overflow', () => {
    test('does not scroll horizontally on desktop', async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 })
        await page.goto(ROSA_BASE + '/')
        await waitForStorefront(page)

        const { scrollWidth, clientWidth } = await hasHorizontalOverflow(page)
        // Allow a 1px rounding tolerance; the bug was a ~150px overflow.
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1)
    })

    test('does not scroll horizontally on mobile', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await page.goto(ROSA_BASE + '/')
        await waitForStorefront(page)

        const { scrollWidth, clientWidth } = await hasHorizontalOverflow(page)
        expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 1)
    })
})
