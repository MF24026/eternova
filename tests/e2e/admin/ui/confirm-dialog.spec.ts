import { test, expect, type Page } from '@playwright/test'

/**
 * ConfirmDialog — the styled, promise-based replacement for window.confirm().
 *
 * Verifies the shared dialog (mounted once in AdminLayout) appears for a
 * destructive action, is design-system styled (cancel + danger accept), and
 * that cancel is non-destructive. The accept path is covered without mutating
 * demo data here — the products archive accept-path lives in products.spec and
 * the settings dirty-guard accept-path in settings.spec.
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

test.describe('ConfirmDialog (useConfirm)', () => {

    test('archiving a product opens the styled confirm; cancel is non-destructive', async ({ page }) => {
        await login(page)
        await page.goto(`${TENANT_BASE}/admin/products`)
        await expect(page.getByRole('button', { name: 'Archivar' }).first()).toBeVisible({ timeout: 15_000 })

        // No native dialog must be used — fail loudly if one ever appears.
        page.on('dialog', (d) => { throw new Error(`Unexpected native dialog: ${d.message()}`) })

        await page.getByRole('button', { name: 'Archivar' }).first().click()

        // Styled dialog with message + both actions.
        await expect(page.locator('[data-testid="confirm-message"]')).toBeVisible()
        await expect(page.locator('[data-testid="confirm-accept"]')).toBeVisible()
        await expect(page.locator('[data-testid="confirm-cancel"]')).toBeVisible()

        // Cancel closes the dialog and archives nothing.
        await page.click('[data-testid="confirm-cancel"]')
        await expect(page.locator('[data-testid="confirm-accept"]')).toHaveCount(0)
        await expect(page.locator('text=Producto archivado')).toHaveCount(0)
    })

    test('Escape on the confirm cancels it (keyboard accessible)', async ({ page }) => {
        await login(page)
        await page.goto(`${TENANT_BASE}/admin/products`)
        await expect(page.getByRole('button', { name: 'Archivar' }).first()).toBeVisible({ timeout: 15_000 })

        await page.getByRole('button', { name: 'Archivar' }).first().click()
        await expect(page.locator('[data-testid="confirm-accept"]')).toBeVisible()

        await page.keyboard.press('Escape')
        await expect(page.locator('[data-testid="confirm-accept"]')).toHaveCount(0)
    })
})
