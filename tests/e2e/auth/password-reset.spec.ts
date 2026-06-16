import { test, expect, type Page } from '@playwright/test'

/**
 * Password reset — pilot auth readiness.
 *
 * Covers the two new SPA pages (forgot-password, reset-password) and the entry
 * link from login. The full token happy-path (consuming a real emailed token)
 * is owned by the PHPUnit suite (tests/Feature/Auth/PasswordResetTest) because
 * the plaintext token only travels in the email and there is no inbox in this
 * environment. Here we drive everything reachable from the browser without one:
 * navigation, the generic confirmation state, the broken-link guard, and the
 * server rejection of an invalid token.
 *
 * Runs on a tenant subdomain (these are guest-only pages). Run --workers=1.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'

async function gotoLogin(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('[data-testid="forgot-link"]', { timeout: 10_000 })
}

test.describe('Password reset (pilot auth)', () => {

    test('login page links to the forgot-password page', async ({ page }) => {
        await gotoLogin(page)
        await page.click('[data-testid="forgot-link"]')
        await expect(page).toHaveURL(/\/forgot-password/)
        await expect(page.locator('[data-testid="forgot-submit"]')).toBeVisible()
    })

    test('submitting an email shows the generic confirmation state', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/forgot-password`)
        await page.fill('[data-testid="forgot-email"]', 'caro@rosaeterna.com')
        await page.click('[data-testid="forgot-submit"]')
        await expect(page.locator('[data-testid="forgot-sent"]')).toBeVisible({ timeout: 10_000 })
    })

    test('a forgot request for an unknown email shows the SAME confirmation (no enumeration)', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/forgot-password`)
        await page.fill('[data-testid="forgot-email"]', 'definitely-nobody@nowhere.test')
        await page.click('[data-testid="forgot-submit"]')
        await expect(page.locator('[data-testid="forgot-sent"]')).toBeVisible({ timeout: 10_000 })
    })

    test('reset-password without a token shows the broken-link state', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/reset-password`)
        await expect(page.locator('[data-testid="reset-invalid-link"]')).toBeVisible({ timeout: 10_000 })
    })

    test('reset-password with an invalid token is rejected by the server', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/reset-password?token=bogus-token&email=caro@rosaeterna.com`)
        await page.fill('[data-testid="reset-password"]', 'NewPassw0rd!')
        await page.fill('[data-testid="reset-password-confirm"]', 'NewPassw0rd!')
        await page.click('[data-testid="reset-submit"]')
        await expect(page.locator('[data-testid="reset-error"]')).toBeVisible({ timeout: 10_000 })
    })
})
