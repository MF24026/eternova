import { test, expect, type Page } from '@playwright/test'

/**
 * U6 (frontend-polish-batch) — Spanish UI copy carries correct accents.
 *
 * Regression guard: strings were authored inline (no vue-i18n) and many were
 * missing their tildes ("administracion", "gestion", "catalogo"...). After the
 * accent sweep, headers render with the correct diacritics.
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button.auth-submit')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

test.describe('UI copy accents (U6)', () => {
    test('admin topbar title is accented', async ({ page }) => {
        await login(page)
        await page.goto(`${TENANT_BASE}/admin/dashboard`)

        // Accented form present, unaccented form absent.
        await expect(page.getByText('Panel de administración').first()).toBeVisible({ timeout: 15_000 })
        await expect(page.getByText('Panel de administracion', { exact: true })).toHaveCount(0)
    })

    test('orders section header uses "Gestión"', async ({ page }) => {
        await login(page)
        await page.goto(`${TENANT_BASE}/admin/orders`)

        await expect(page.getByText('Gestión', { exact: true }).first()).toBeVisible({ timeout: 15_000 })
    })
})
