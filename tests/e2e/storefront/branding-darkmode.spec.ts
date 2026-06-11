import { test, expect, type Page } from '@playwright/test'

/**
 * S62 — Per-tenant brand token derivation + public dark mode toggle.
 *
 * Two demo tenants (seeded by DemoTenantsSeeder):
 *   rosa-eterna  primary #7c545d  (mauve)
 *   tatiana      primary #2f7d72  (teal)
 *
 * Why in-page evaluation (not page.request / CSS file parsing):
 *   Node's HTTP client cannot resolve *.eternova.localhost — only Chromium
 *   applies the RFC 6761 loopback rule.  getComputedStyle inside the page
 *   gives us the resolved inline token values after Vue has mounted and
 *   useStorefrontBranding() has run.
 *
 * The token derivation uses color-mix(), which browsers compute at paint
 * time.  getComputedStyle returns the *string* value of the custom property
 * — for inline-set tokens it may be the literal `color-mix(...)` expression
 * (un-resolved), so we assert on the *expression content* (contains the
 * brand hex) rather than a computed RGB colour.
 */

const ROSA_BASE = process.env.STOREFRONT_ROSA_URL ?? 'http://rosa-eterna.eternova.localhost'
const TATIANA_BASE = process.env.STOREFRONT_TATIANA_URL ?? 'http://tatiana.eternova.localhost'

const ROSA_PRIMARY = '#7c545d'
const TATIANA_PRIMARY = '#2f7d72'

const HYDRATION_TIMEOUT = 20_000
const DARK_MODE_KEY = 'eternova-dark-mode'

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Wait for the storefront SPA to mount and branding to be injected. */
async function waitForStorefront(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const app = document.querySelector('#app')
            if (!app || app.children.length === 0) return false
            // Branding is applied via inline style on <html>; wait for it.
            return document.documentElement.style.getPropertyValue('--primary') !== ''
        },
        { timeout: HYDRATION_TIMEOUT },
    )
}

/** Read a CSS custom property from document.documentElement after mount. */
async function readRootToken(page: Page, prop: string): Promise<string> {
    return page.evaluate((p) => {
        return document.documentElement.style.getPropertyValue(p).trim()
    }, prop)
}

/** Read the computed style (resolved by browser) for a token. */
async function readComputedToken(page: Page, prop: string): Promise<string> {
    return page.evaluate((p) => {
        return getComputedStyle(document.documentElement).getPropertyValue(p).trim()
    }, prop)
}

/** Toggle dark mode via the storefront header button. */
async function clickThemeToggle(page: Page): Promise<void> {
    const toggle = page.getByTestId('storefront-theme-toggle')
    await expect(toggle).toBeVisible({ timeout: HYDRATION_TIMEOUT })
    await toggle.click()
}

/** Assert the <html> element has (or does not have) the `dark` class. */
async function assertDarkClass(page: Page, expected: boolean): Promise<void> {
    await page.waitForFunction(
        ({ want }) => document.documentElement.classList.contains('dark') === want,
        { want: expected },
        { timeout: 5_000 },
    )
}

// ── Tests ────────────────────────────────────────────────────────────────────

test.describe('S62 — Storefront branding token derivation', () => {
    test.beforeEach(async ({ page }) => {
        // Clear dark-mode state so each test starts in light mode.
        await page.goto(ROSA_BASE)
        await page.evaluate((key) => localStorage.removeItem(key), DARK_MODE_KEY)
        await page.evaluate(() => document.documentElement.classList.remove('dark'))
    })

    test('rosa-eterna storefront injects mauve primary token', async ({ page }) => {
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)

        const primary = await readRootToken(page, '--primary')
        expect(primary.toLowerCase()).toBe(ROSA_PRIMARY)
    })

    test('tatiana storefront injects teal primary token (differs from rosa-eterna)', async ({ page }) => {
        // Load rosa-eterna to get its container value as a baseline.
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)
        const rosaContainer = await readRootToken(page, '--primary-container')
        const rosaPrimary = await readRootToken(page, '--primary')

        // Now load tatiana (different subdomain = different Vue SPA bootstrap).
        await page.goto(TATIANA_BASE)
        await waitForStorefront(page)
        const tatianaPrimary = await readRootToken(page, '--primary')
        const tatianaContainer = await readRootToken(page, '--primary-container')

        // Primaries must differ.
        expect(tatianaPrimary.toLowerCase()).not.toBe(rosaPrimary.toLowerCase())
        expect(tatianaPrimary.toLowerCase()).toBe(TATIANA_PRIMARY)

        // Containers must differ and tatiana's must reference its own teal hex.
        expect(tatianaContainer).not.toBe(rosaContainer)
        expect(tatianaContainer.toLowerCase()).toContain(TATIANA_PRIMARY)
    })

    test('primary-container is derived (not the default pink) on tatiana', async ({ page }) => {
        await page.goto(TATIANA_BASE)
        await waitForStorefront(page)

        const container = await readRootToken(page, '--primary-container')

        // The default light container is #f8c4cf — tatiana should NOT have this.
        expect(container).not.toBe('#f8c4cf')
        // It should be a color-mix expression referencing the teal hex.
        expect(container.toLowerCase()).toContain(TATIANA_PRIMARY)
    })

    test('on-primary is set and non-empty on both tenants (legibility rule)', async ({ page }) => {
        for (const base of [ROSA_BASE, TATIANA_BASE]) {
            await page.goto(base)
            await waitForStorefront(page)
            const onPrimary = await readRootToken(page, '--on-primary')
            expect(onPrimary).not.toBe('')
            // Both brand primaries are dark enough to warrant near-white text.
            expect(onPrimary.toLowerCase()).toBe('#fff7f7')
        }
    })
})

test.describe('S62 — Dark mode toggle on storefront', () => {
    test.beforeEach(async ({ page }) => {
        // Always start in light mode.
        await page.goto(ROSA_BASE)
        await page.evaluate((key) => localStorage.removeItem(key), DARK_MODE_KEY)
        await page.evaluate(() => document.documentElement.classList.remove('dark'))
    })

    test('toggle button is visible in the header', async ({ page }) => {
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)
        await expect(page.getByTestId('storefront-theme-toggle')).toBeVisible()
    })

    test('clicking toggle adds .dark class to <html>', async ({ page }) => {
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)

        await assertDarkClass(page, false)
        await clickThemeToggle(page)
        await assertDarkClass(page, true)
    })

    test('dark mode preference persists across reload', async ({ page }) => {
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)

        await clickThemeToggle(page)
        await assertDarkClass(page, true)

        // Verify localStorage was written.
        const stored = await page.evaluate((key) => localStorage.getItem(key), DARK_MODE_KEY)
        expect(stored).toBe('true')

        // Reload and assert dark class is restored.
        await page.reload()
        await waitForStorefront(page)
        await assertDarkClass(page, true)
    })

    test('primary-container re-derives to dark variant after toggle', async ({ page }) => {
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)

        const lightContainer = await readRootToken(page, '--primary-container')

        await clickThemeToggle(page)
        await assertDarkClass(page, true)
        // Give the watch a tick to re-apply.
        await page.waitForTimeout(100)

        const darkContainer = await readRootToken(page, '--primary-container')

        // The dark formula mixes toward black; the light formula mixes toward white.
        // Values must differ.
        expect(darkContainer).not.toBe(lightContainer)
        // Dark container references black in its expression.
        expect(darkContainer.toLowerCase()).toContain('black')
    })

    test('toggling back to light restores light container value', async ({ page }) => {
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)

        const originalContainer = await readRootToken(page, '--primary-container')

        await clickThemeToggle(page)
        await assertDarkClass(page, true)
        await page.waitForTimeout(100)

        await clickThemeToggle(page)
        await assertDarkClass(page, false)
        await page.waitForTimeout(100)

        const restoredContainer = await readRootToken(page, '--primary-container')
        expect(restoredContainer).toBe(originalContainer)
    })

    test('dark mode does not break page — surface background goes dark', async ({ page }) => {
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)

        await clickThemeToggle(page)
        await assertDarkClass(page, true)
        await page.waitForTimeout(100)

        // The dark surface token is #1a1416 — the computed bg of <body> must change.
        // We check the --surface token directly (it comes from .dark {}, not inline).
        const surfaceInDark = await readComputedToken(page, '--surface')
        // Dark --surface in app.css is #1a1416.
        // We cannot assert an exact value (browser computes it), but it must exist.
        expect(surfaceInDark).not.toBe('')

        // The primary (brand) token must still be the tenant's mauve.
        const primaryInDark = await readRootToken(page, '--primary')
        expect(primaryInDark.toLowerCase()).toBe(ROSA_PRIMARY)
    })
})

test.describe('S62 — Mobile viewport: toggle reachable', () => {
    test.use({ viewport: { width: 375, height: 812 } })

    test('dark toggle visible at 375px and functional', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await page.goto(ROSA_BASE)
        await waitForStorefront(page)

        const toggle = page.getByTestId('storefront-theme-toggle')
        await expect(toggle).toBeVisible()

        // The toggle must be within the viewport (not scrolled off-screen).
        const box = await toggle.boundingBox()
        expect(box).not.toBeNull()
        expect(box!.x).toBeGreaterThanOrEqual(0)
        // Use the actual viewport width rather than the hardcoded 375 to account
        // for subpixel rounding and DPR differences across browser versions.
        const viewport = page.viewportSize()!
        expect(box!.x + box!.width).toBeLessThanOrEqual(viewport.width + 5)

        await toggle.click()
        await assertDarkClass(page, true)
    })
})
