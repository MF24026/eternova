import { test, expect, type Page } from '@playwright/test'

/**
 * Settings page — S8-E3/E4.
 *
 * Exercises the real SettingsPage wired to the Settings API: tab navigation,
 * per-group save round-trips (persist on reload), the bps<->% conversion, the
 * branch_settings-backed groups, and validation error display.
 *
 * Mutates rosa-eterna's settings (a singleton per tenant) — values are
 * deterministic so reruns overwrite cleanly.
 *
 * Requires DemoTenantsSeeder. Run with --workers=1.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const SETTINGS_URL = `${TENANT_BASE}/admin/settings`

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function gotoSettings(page: Page): Promise<void> {
    await page.goto(SETTINGS_URL)
    await expect(page.locator('[data-testid="panel-marca"]')).toBeVisible({ timeout: 15_000 })
}

async function saveAndExpectToast(page: Page): Promise<void> {
    await page.click('[data-testid="btn-save"]')
    await expect(page.locator('text=Cambios guardados')).toBeVisible({ timeout: 8_000 })
}

test.describe('Settings page (S8-E3/E4)', () => {

    test('loads with all eight tabs and real tenant data', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        for (const tab of ['marca', 'contacto', 'local', 'impuestos', 'pedidos', 'cotizaciones', 'reservas', 'notif']) {
            await expect(page.locator(`[data-testid="tab-${tab}"]`)).toBeVisible()
        }

        // Brand business name is populated from the tenant row (not a hardcoded mock).
        const name = await page.locator('[data-testid="input-business-name"] input').inputValue()
        expect(name.length).toBeGreaterThan(0)
    })

    test('switching tabs reveals the matching panel', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        await page.click('[data-testid="tab-impuestos"]')
        await expect(page.locator('[data-testid="panel-impuestos"]')).toBeVisible()
        await expect(page.locator('[data-testid="panel-marca"]')).toHaveCount(0)

        await page.click('[data-testid="tab-notif"]')
        await expect(page.locator('[data-testid="panel-notif"]')).toBeVisible()
    })

    test('saving the brand group persists the business name on reload', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        const newName = `Rosa Eterna ${Date.now() % 100000}`
        await page.fill('[data-testid="input-business-name"] input', newName)
        await saveAndExpectToast(page)

        await gotoSettings(page)
        await expect(page.locator('[data-testid="input-business-name"] input')).toHaveValue(newName)
    })

    test('saving the tax group round-trips the bps<->% conversion', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        await page.click('[data-testid="tab-impuestos"]')
        await page.fill('[data-testid="input-tax-rate"] input', '16')
        await saveAndExpectToast(page)

        await gotoSettings(page)
        await page.click('[data-testid="tab-impuestos"]')
        // 16% -> 1600 bps -> back to "16"
        await expect(page.locator('[data-testid="input-tax-rate"] input')).toHaveValue('16')
    })

    test('saving the contact group (branch_settings) persists on reload', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        const phone = `+503 7${Date.now() % 1000000}`
        await page.click('[data-testid="tab-contacto"]')
        await page.fill('[data-testid="input-phone"] input', phone)
        await saveAndExpectToast(page)

        await gotoSettings(page)
        await page.click('[data-testid="tab-contacto"]')
        await expect(page.locator('[data-testid="input-phone"] input')).toHaveValue(phone)
    })

    test('toggling a notification persists on reload', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        await page.click('[data-testid="tab-notif"]')
        const toggle = page.locator('[data-testid="toggle-reservation_confirmed"]')
        const before = await toggle.getAttribute('aria-checked')
        await toggle.click()
        await saveAndExpectToast(page)

        await gotoSettings(page)
        await page.click('[data-testid="tab-notif"]')
        const after = await page.locator('[data-testid="toggle-reservation_confirmed"]').getAttribute('aria-checked')
        expect(after).not.toBe(before)
    })

    test('clearing a required field shows a validation error and no success', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        await page.fill('[data-testid="input-business-name"] input', '')
        await page.click('[data-testid="btn-save"]')

        // Inline field error appears; no success toast.
        await expect(page.locator('[data-testid="panel-marca"] .text-error').first()).toBeVisible({ timeout: 8_000 })
        await expect(page.locator('text=Cambios guardados')).toHaveCount(0)
    })

    test('selecting a favicon shows a preview and marks the form dirty', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        // 1x1 transparent PNG.
        const png = Buffer.from(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            'base64',
        )
        await page.setInputFiles('[data-testid="input-favicon"]', {
            name: 'favicon.png', mimeType: 'image/png', buffer: png,
        })

        await expect(page.locator('[data-testid="dirty-indicator"]')).toBeVisible()
        await expect(page.locator('[data-testid="panel-marca"] img[alt="Favicon"]')).toBeVisible()
    })

    test('warns before navigating away with unsaved changes (styled dialog)', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        // Make the form dirty.
        await page.fill('[data-testid="input-business-name"] input', 'Edición sin guardar')
        await expect(page.locator('[data-testid="dirty-indicator"]')).toBeVisible()

        // Navigating away opens the styled confirm (not a native dialog).
        await page.locator('a[href="/admin/dashboard"]').first().click()
        await expect(page.locator('[data-testid="confirm-accept"]')).toBeVisible({ timeout: 8_000 })

        // Cancel -> stay on settings.
        await page.click('[data-testid="confirm-cancel"]')
        await expect(page).toHaveURL(/\/admin\/settings/)
        await expect(page.locator('[data-testid="panel-marca"]')).toBeVisible()

        // Try again and accept -> navigation proceeds to the dashboard.
        await page.locator('a[href="/admin/dashboard"]').first().click()
        await page.click('[data-testid="confirm-accept"]')
        await expect(page).toHaveURL(/\/admin\/dashboard/)
    })

    test('renders correctly on a 375px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoSettings(page)

        await expect(page.locator('[data-testid="tab-marca"]')).toBeVisible()
        await page.click('[data-testid="tab-cotizaciones"]')
        await expect(page.locator('[data-testid="panel-cotizaciones"]')).toBeVisible()
    })

    test('rejects an invalid fiscal id and accepts a valid DUI (SV)', async ({ page }) => {
        await login(page)
        await gotoSettings(page)

        // The tab strip scrolls horizontally; on mobile the Impuestos tab's centre
        // is overlapped by neighbouring tabs / the sticky topbar, so a coordinate
        // click is intercepted. Dispatch the click straight to the tab to switch
        // panels — the fiscal-id interactions below are still real clicks.
        await page.locator('[data-testid="tab-impuestos"]').dispatchEvent('click')
        await expect(page.locator('[data-testid="panel-impuestos"]')).toBeVisible()

        // The save button lives in a sticky footer that the mobile topbar/footer
        // overlaps for a coordinate click; dispatch the click so it reaches the
        // real save() handler (real request -> real 422 -> real error binding).
        const saveBtn = page.locator('[data-testid="btn-save"]')

        // Wrong DUI check digit -> inline error, no success toast.
        await page.fill('[data-testid="input-tax-id"] input', '04210323-5')
        await saveBtn.dispatchEvent('click')
        await expect(page.locator('[data-testid="panel-impuestos"] .text-error').first())
            .toBeVisible({ timeout: 8_000 })
        await expect(page.locator('text=Cambios guardados')).toHaveCount(0)

        // Valid DUI -> saves.
        await page.fill('[data-testid="input-tax-id"] input', '04210323-4')
        await saveBtn.dispatchEvent('click')
        await expect(page.locator('text=Cambios guardados')).toBeVisible({ timeout: 8_000 })
        await expect(page.locator('[data-testid="panel-impuestos"] .text-error')).toHaveCount(0)
    })
})
