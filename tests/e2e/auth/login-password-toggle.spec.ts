import { test, expect } from '@playwright/test'

/**
 * U7 (frontend-polish-batch) — the login password field has a show/hide toggle.
 *
 * Uses the tenant subdomain (only Chromium resolves *.eternova.localhost) so it
 * runs inside the Sail container like the other subdomain specs.
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'

test.describe('login password visibility toggle (U7)', () => {
    test('toggles the password input between hidden and visible', async ({ page }) => {
        await page.goto(`${TENANT_BASE}/login`)

        const input = page.locator('#password')
        const toggle = page.locator('[data-testid="toggle-password"]')

        await expect(input).toHaveAttribute('type', 'password')

        await toggle.click()
        await expect(input).toHaveAttribute('type', 'text')

        await toggle.click()
        await expect(input).toHaveAttribute('type', 'password')
    })
})
