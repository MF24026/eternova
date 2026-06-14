import { test, expect, type Page } from '@playwright/test'

/**
 * Plan gating UI — S9-E3.
 *
 * rosa-eterna is on the Pro plan, whose `custom_domain` limit is false. The
 * custom-domain card in Settings → Localización must therefore render LOCKED
 * (Pattern B: visible content + upgrade overlay + CTA), not hidden.
 *
 * Requires DemoTenantsSeeder. Run --workers=1.
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

async function gotoLocalizacion(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/admin/settings`)
    await expect(page.locator('[data-testid="panel-marca"]')).toBeVisible({ timeout: 15_000 })
    await page.click('[data-testid="tab-local"]')
    await expect(page.locator('[data-testid="panel-local"]')).toBeVisible()
}

test.describe('Plan gating — custom domain (S9-E3)', () => {

    test('the custom-domain feature is visible but locked on the Pro plan', async ({ page }) => {
        await login(page)
        await gotoLocalizacion(page)

        // The gated card and its content are present (Pattern B: visible, not hidden).
        await expect(page.locator('[data-testid="panel-custom-domain"]')).toBeVisible()
        await expect(page.locator('[data-testid="input-custom-domain"]')).toBeAttached()

        // The lock overlay + upgrade CTA are shown because custom_domain = false on Pro.
        await expect(page.locator('[data-testid="upgrade-overlay"]')).toBeVisible()
        await expect(page.locator('[data-testid="upgrade-cta"]')).toBeVisible()
        await expect(page.locator('[data-testid="upgrade-overlay"]')).toContainText('Enterprise')
    })

    test('clicking the upgrade CTA surfaces a plan-management hint', async ({ page }) => {
        await login(page)
        await gotoLocalizacion(page)

        await page.click('[data-testid="upgrade-cta"]')
        await expect(page.locator('text=gestión de plan')).toBeVisible({ timeout: 8_000 })
    })
})
