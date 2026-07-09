import { test, expect, type Page } from '@playwright/test'

/**
 * Admin theme selector (Phase 1). Switching to Minimalista themes the whole admin
 * (root gains `theme-minimal`), persists as the tenant default across reload, and
 * reverts cleanly. Seeded rosa-eterna tenant; only Chromium resolves the subdomain.
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

test('Minimalista themes the admin, persists across reload, and reverts', async ({ page }) => {
    await login(page)
    await page.goto(`${TENANT_BASE}/admin/settings`, { waitUntil: 'domcontentloaded' })

    // Switch to Minimalista -> root gains the theme class live.
    await page.locator('[data-testid="theme-option-minimal"]').click()
    await expect(page.locator('html')).toHaveClass(/theme-minimal/)

    // Persist the brand group.
    await page.locator('[data-testid="btn-save"]').click()
    await page.waitForTimeout(1200)

    // Reload the app: the tenant now boots into Minimalista (from /me).
    await page.goto(`${TENANT_BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('html')).toHaveClass(/theme-minimal/)

    // Revert to Ethereal so the demo tenant is left as it was.
    await page.goto(`${TENANT_BASE}/admin/settings`, { waitUntil: 'domcontentloaded' })
    await page.locator('[data-testid="theme-option-ethereal"]').click()
    await expect(page.locator('html')).not.toHaveClass(/theme-minimal/)
    await page.locator('[data-testid="btn-save"]').click()
    await page.waitForTimeout(1200)
})
