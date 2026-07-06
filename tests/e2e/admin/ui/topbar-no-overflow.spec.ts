import { test, expect, type Page } from '@playwright/test'

/**
 * U7 (frontend-polish-batch) — the admin topbar must not cause horizontal
 * overflow on mobile.
 *
 * Regression guard: the topbar's gap + padding pushed its controls past the
 * viewport on 375px (the user chip spilled ~7px past the edge). Tightening the
 * mobile gap/padding removes the horizontal scroll.
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

test.describe('admin topbar overflow (U7)', () => {
    test('does not scroll horizontally on a 375px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)

        await page.goto(`${TENANT_BASE}/admin/dashboard`)
        await expect(page.locator('[data-testid="dashboard-root"]')).toBeVisible({ timeout: 15_000 })

        const overflow = await page.evaluate(
            () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
        )
        expect(overflow, 'admin page should not overflow horizontally on mobile').toBe(false)
    })
})
