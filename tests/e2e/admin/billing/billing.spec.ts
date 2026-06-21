import { test, expect, type Page } from '@playwright/test'

/**
 * Tenant billing UI — Phase 6b.
 *
 * Exercises the real BillingPage wired to /api/v1/account/billing/*: load, subscribe to a plan
 * (creates a Wompi recurring link — FakeGateway returns a deterministic hosted affiliation URL),
 * see the affiliation panel + link, refresh state, and the invoices section.
 *
 * Uses the `tatiana` demo tenant (basico), which the seeder leaves WITHOUT a Subscription row, so
 * the plan picker is always offered and the subscribe flow is deterministic. Activation is
 * webhook-driven, so the tenant never becomes `active` here (no cancel button) — that path is
 * covered by PHPUnit (BillingAccountApiTest).
 *
 * Requires DemoTenantsSeeder + BILLING_DRIVER=fake. Run with --workers=1.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://tatiana.eternova.localhost'
const OWNER = { email: 'tati@regalostatiana.com', password: 'DemoBasic123!' }
const BILLING_URL = `${TENANT_BASE}/admin/billing`

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

test.describe('Tenant billing UI (Phase 6b)', () => {
    test('owner subscribes and sees the affiliation panel with a hosted link', async ({ page }) => {
        await login(page)
        await page.goto(BILLING_URL)
        await expect(page.locator('[data-testid="billing-page"]')).toBeVisible({ timeout: 15_000 })

        // No active subscription -> the plan picker is offered.
        await expect(page.locator('[data-testid="plan-card-basico"]')).toBeVisible({ timeout: 10_000 })

        // Subscribe -> the affiliation panel appears with a non-empty hosted link.
        await page.click('[data-testid="subscribe-btn-basico"]')
        await expect(page.locator('[data-testid="affiliation-panel"]')).toBeVisible({ timeout: 15_000 })

        const openLink = page.locator('[data-testid="affiliation-open-link"]')
        const href = await openLink.getAttribute('href')
        expect(href).toBeTruthy()

        // The open-link control must actually navigate in a new tab. Regression guard: a <button>
        // nested inside an <a> swallowed the click, so nothing opened.
        const popupPromise = page.waitForEvent('popup', { timeout: 5_000 })
        await openLink.click()
        const popup = await popupPromise
        await popup.close()

        // Refresh state keeps the page healthy (no crash).
        await page.click('[data-testid="affiliation-refresh"]')
        await expect(page.locator('[data-testid="billing-page"]')).toBeVisible()
    })

    test('shows the invoices section and no cancel button while not active', async ({ page }) => {
        await login(page)
        await page.goto(BILLING_URL)
        await expect(page.locator('[data-testid="billing-page"]')).toBeVisible({ timeout: 15_000 })

        await expect(page.getByText('Facturas')).toBeVisible()
        // tatiana never reaches `active` here -> the cancel action must be absent.
        await expect(page.locator('[data-testid="cancel-subscription-btn"]')).toHaveCount(0)
    })

    test('an active owner can switch plans and is sent to re-affiliate', async ({ page }) => {
        // rosa-eterna is the active demo tenant (the picker is offered for switching while active).
        const ACTIVE_BASE = process.env.POS_ACTIVE_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
        await page.goto(`${ACTIVE_BASE}/login`)
        await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
        await page.fill('input[type="email"]', 'caro@rosaeterna.com')
        await page.fill('input[type="password"]', 'DemoPro123!')
        await page.click('button[type="submit"]')
        await page.waitForURL('**/admin/**', { timeout: 15_000 })

        await page.goto(`${ACTIVE_BASE}/admin/billing`)
        await expect(page.locator('[data-testid="billing-page"]')).toBeVisible({ timeout: 15_000 })

        // While active, the current plan is marked and the picker stays available for switching.
        await expect(page.locator('[data-testid^="current-plan-"]')).toBeVisible({ timeout: 10_000 })

        // Switch to a different plan -> the affiliation panel appears even though the sub is active.
        await page.locator('[data-testid^="subscribe-btn-"]').first().click()
        await expect(page.locator('[data-testid="affiliation-panel"]')).toBeVisible({ timeout: 15_000 })
    })
})
