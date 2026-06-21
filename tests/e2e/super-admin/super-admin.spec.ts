import { test, expect, type Page } from '@playwright/test'

/**
 * SuperAdmin operator console — 7b.
 *
 * The platform operator (is_super_admin) lands on /super-admin, sees billing metrics, lists
 * tenants, opens a tenant, and runs an operator action (extend trial) that records an audit entry.
 * Also asserts the route guard bounces a non-super user away from /super-admin/*.
 *
 * Requires DemoTenantsSeeder + SuperAdminUserSeeder. Run with --workers=1.
 */

const BASE = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost'
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://tatiana.eternova.localhost'
const SUPER = { email: 'admin@eternova.app', password: 'ChangeMe123!' }
const TENANT_OWNER = { email: 'tati@regalostatiana.com', password: 'DemoBasic123!' }

async function login(page: Page, base: string, creds: { email: string; password: string }): Promise<void> {
    await page.goto(`${base}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', creds.email)
    await page.fill('input[type="password"]', creds.password)
    await page.locator('button.auth-submit').click()
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

test.describe('SuperAdmin console (7b)', () => {
    test('super-admin sees metrics, lists tenants, and runs an operator action', async ({ page }) => {
        await login(page, BASE, SUPER)

        await page.goto(`${BASE}/super-admin`)
        await expect(page.locator('[data-testid="superadmin-metrics-page"]')).toBeVisible({ timeout: 15_000 })

        // Navigate by URL (the sidebar nav is off-canvas on mobile).
        await page.goto(`${BASE}/super-admin/tenants`)
        await expect(page.locator('[data-testid="superadmin-tenants-page"]')).toBeVisible({ timeout: 15_000 })

        // Open the trialing demo tenant (Tatiana) so extend-trial is a valid action.
        await page.locator('[data-testid="tenant-row"]', { hasText: 'Tatiana' }).click()
        await expect(page.locator('[data-testid="superadmin-tenant-detail"]')).toBeVisible({ timeout: 15_000 })

        // Extend trial with a reason -> the action form closes on success and an audit row shows.
        await page.click('[data-testid="extend-trial-btn"]')
        await page.fill('[data-testid="action-reason"] input', 'E2E extend trial')
        await page.click('[data-testid="action-submit"]')
        await expect(page.locator('[data-testid="action-form"]')).toBeHidden({ timeout: 15_000 })
        await expect(page.locator('[data-testid="audit-row"]').first()).toBeVisible()
    })

    test('a non-super user is bounced from /super-admin', async ({ page }) => {
        await login(page, TENANT_BASE, TENANT_OWNER)

        await page.goto(`${TENANT_BASE}/super-admin/metrics`)
        // The route guard redirects non-super users to their tenant dashboard.
        await page.waitForURL('**/admin/dashboard', { timeout: 10_000 })
        await expect(page.locator('[data-testid="superadmin-metrics-page"]')).toHaveCount(0)
    })
})
