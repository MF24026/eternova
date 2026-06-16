import { test, expect, type Page } from '@playwright/test'

/**
 * Onboarding tour — S9-E4.
 *
 * The owner's first visit shows a once-per-tenant welcome tour. Persistence is
 * via localStorage (`eternova_onboarding_seen_{tenantId}`); clearing it forces
 * the tour to appear, dismissing it must persist so it never reappears.
 *
 * Requires DemoTenantsSeeder (caro@rosaeterna.com is the rosa-eterna owner).
 * Run --workers=1.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function loginFresh(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    // Clear any prior "tour seen" flag, and force the tour ON under automation
    // (it is suppressed under navigator.webdriver unless this flag is set).
    await page.evaluate(() => {
        localStorage.clear()
        localStorage.setItem('eternova_force_tour', '1')
    })
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

test.describe('Onboarding tour (S9-E4)', () => {

    test('shows the welcome tour to the owner on first login', async ({ page }) => {
        await loginFresh(page)

        await expect(page.locator('[data-testid="onboarding-tour"]')).toBeVisible({ timeout: 10_000 })
        await expect(page.locator('[data-testid="tour-title"]')).toContainText('bienvenida')
    })

    test('stepping through to the end finishes the tour and it does not reappear', async ({ page }) => {
        await loginFresh(page)
        await expect(page.locator('[data-testid="onboarding-tour"]')).toBeVisible({ timeout: 10_000 })

        // 5 steps: click Siguiente 4 times, then Comenzar (finish).
        for (let i = 0; i < 4; i++) {
            await page.click('[data-testid="tour-next"]')
        }
        await page.click('[data-testid="tour-finish"]')

        await expect(page.locator('[data-testid="onboarding-tour"]')).toHaveCount(0)

        // Reload — the tour must NOT reappear (seen flag persisted).
        await page.reload()
        await expect(page.locator('[data-testid="dashboard-root"]')).toBeVisible({ timeout: 15_000 })
        await expect(page.locator('[data-testid="onboarding-tour"]')).toHaveCount(0)
    })

    test('skipping the tour dismisses it and persists', async ({ page }) => {
        await loginFresh(page)
        await expect(page.locator('[data-testid="onboarding-tour"]')).toBeVisible({ timeout: 10_000 })

        await page.click('[data-testid="tour-skip"]')
        await expect(page.locator('[data-testid="onboarding-tour"]')).toHaveCount(0)

        await page.reload()
        await expect(page.locator('[data-testid="dashboard-root"]')).toBeVisible({ timeout: 15_000 })
        await expect(page.locator('[data-testid="onboarding-tour"]')).toHaveCount(0)
    })

    test('is anchored to the dashboard: not shown over a non-dashboard landing', async ({ page }) => {
        // Land directly on Settings (e.g. a bookmark) on first login.
        await page.goto(`${TENANT_BASE}/login?redirect=/admin/settings`)
        await page.evaluate(() => {
            localStorage.clear()
            localStorage.setItem('eternova_force_tour', '1')
        })
        await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
        await page.fill('input[type="email"]', OWNER.email)
        await page.fill('input[type="password"]', OWNER.password)
        await page.click('button[type="submit"]')
        await page.waitForURL('**/admin/settings', { timeout: 15_000 })

        // The welcome tour must NOT pop over Settings.
        await expect(page.locator('[data-testid="panel-marca"]')).toBeVisible({ timeout: 10_000 })
        await expect(page.locator('[data-testid="onboarding-tour"]')).toHaveCount(0)

        // It surfaces once the owner reaches the dashboard it describes.
        await page.goto(`${TENANT_BASE}/admin/dashboard`)
        await expect(page.locator('[data-testid="onboarding-tour"]')).toBeVisible({ timeout: 10_000 })
    })
})
