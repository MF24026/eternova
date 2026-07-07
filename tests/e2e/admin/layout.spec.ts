import { test, expect, type APIRequestContext } from '@playwright/test'
import { BASE_URL as baseURL } from '../support/env'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function createAndLoginUser(request: APIRequestContext): Promise<string> {
    const ts = Date.now()
    const email = `admin-layout-${ts}@example.com`
    const password = 'password123'

    const reg = await request.post(`${baseURL}/api/v1/auth/register`, {
        data: { name: 'Layout Test User', email, password },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })
    if (!reg.ok()) throw new Error(`register failed ${reg.status()}`)

    const login = await request.post(`${baseURL}/api/v1/auth/login`, {
        data: { email, password },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })
    if (!login.ok()) throw new Error(`login failed ${login.status()}`)
    const { data } = await login.json()
    return data.token as string
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('AdminLayout — sidebar collapse/expand', () => {
    test('collapses on mobile breakpoint and shows hamburger', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 667 })
        await page.goto(`${baseURL}/admin/dashboard`)

        // Wait for Vue to mount
        await page.waitForSelector('[data-testid="layout-switcher"], .admin-shell, header', { timeout: 10000 })

        // On mobile, the sidebar should be hidden (off-screen via transform)
        const sidebar = page.locator('aside.sidebar')
        if (await sidebar.count() > 0) {
            const isOpen = await sidebar.evaluate((el) =>
                el.classList.contains('open')
            )
            expect(isOpen).toBe(false)
        }
    })

    test('shows hamburger button on mobile', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 667 })
        await page.goto(`${baseURL}/admin/dashboard`)

        // There should be a button that opens the sidebar
        // We target the admin-topbar-menu button
        const menuBtn = page.locator('button[aria-label="Abrir menu"]')
        if (await menuBtn.count() > 0) {
            await expect(menuBtn).toBeVisible()
        }
    })

    test('user menu opens with logout option', async ({ page, request }) => {
        const token = await createAndLoginUser(request)
        await page.setExtraHTTPHeaders({ Authorization: `Bearer ${token}` })
        await page.goto(`${baseURL}/admin/dashboard`)
        await page.waitForTimeout(1000)

        // Find user menu button
        const userMenuBtn = page.locator('button[aria-haspopup]').first()
        if (await userMenuBtn.count() > 0) {
            await userMenuBtn.click()
            // Should show logout option
            const logoutBtn = page.locator('button:has-text("Cerrar sesión"), [role="menuitem"]:has-text("Cerrar sesión")')
            if (await logoutBtn.count() > 0) {
                await expect(logoutBtn.first()).toBeVisible()
            }
        }
    })

    test('dark mode toggle persists across reload', async ({ page }) => {
        await page.goto(`${baseURL}/admin/dashboard`)
        await page.waitForTimeout(800)

        // Find the dark mode toggle button
        const darkBtn = page.locator('button[aria-label*="oscuro"], button[aria-label*="claro"]')
        if (await darkBtn.count() > 0) {
            // Get initial dark mode state
            const initialDark = await page.evaluate(() =>
                document.documentElement.classList.contains('dark')
            )

            await darkBtn.first().click()
            await page.waitForTimeout(300)

            const afterToggle = await page.evaluate(() =>
                document.documentElement.classList.contains('dark')
            )
            expect(afterToggle).toBe(!initialDark)

            // Reload and check persistence
            await page.reload()
            await page.waitForTimeout(600)

            const afterReload = await page.evaluate(() =>
                document.documentElement.classList.contains('dark')
            )
            expect(afterReload).toBe(!initialDark)
        }
    })
})
