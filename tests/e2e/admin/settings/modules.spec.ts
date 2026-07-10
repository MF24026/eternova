import { test, expect, type Page } from '@playwright/test'

/**
 * Business-vertical module gating: toggling a module in Settings > Módulos hides and
 * restores its nav item (and the API 403s it — covered by PHPUnit). Seeded rosa-eterna
 * (business_type 'otro') has Cotizaciones enabled by default. Round-trips to leave the
 * demo tenant as it was.
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

async function toggleQuotations(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/admin/settings`, { waitUntil: 'domcontentloaded' })
    await page.getByRole('button', { name: /m[oó]dulos/i }).click()
    await page.locator('[data-testid="toggle-module-quotations"]').click()
    await page.locator('[data-testid="btn-save"]').click()
    await page.waitForTimeout(1200)
}

test('toggling Cotizaciones in Settings hides and restores its nav item', async ({ page }) => {
    await login(page)

    // Present by default (otro giro enables quotations).
    await page.goto(`${TENANT_BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('a.sidebar-item[href="/admin/quotations"]')).toHaveCount(1)

    // Turn it off, reload: /me now reports it disabled -> nav item gone.
    await toggleQuotations(page)
    await page.goto(`${TENANT_BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('a.sidebar-item[href="/admin/quotations"]')).toHaveCount(0)

    // Restore so the demo tenant is left as it was.
    await toggleQuotations(page)
    await page.goto(`${TENANT_BASE}/admin/dashboard`, { waitUntil: 'domcontentloaded' })
    await expect(page.locator('a.sidebar-item[href="/admin/quotations"]')).toHaveCount(1)
})
