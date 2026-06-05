import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

/**
 * Register a fresh user and return their credentials.
 * Uses the REST API so tests are independent of UI state.
 */
async function createUser(request: APIRequestContext): Promise<{ name: string; email: string; password: string }> {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    const user = {
        name: `Test User ${ts}`,
        email: `test-${ts}-${suffix}@example.com`,
        password: 'password123',
    }

    const res = await request.post(`${baseURL}/api/v1/auth/register`, {
        data: user,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })

    if (!res.ok()) {
        const body = await res.text()
        throw new Error(`register failed ${res.status()}: ${body}`)
    }

    return user
}

/**
 * Wait for Vue Router to mount content into #app.
 */
async function waitForApp(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const el = document.querySelector('#app')
            return el !== null && el.children.length > 0
        },
        { timeout: 15_000 },
    )
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Login flow', () => {
    test('login with valid credentials redirects to dashboard', async ({ page, request }) => {
        const user = await createUser(request)

        await page.goto(`${baseURL}/login`)
        await waitForApp(page)

        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Entrar' }).click()

        await page.waitForURL(`${baseURL}/admin/dashboard`, { timeout: 15_000 })
        await expect(page).toHaveURL(`${baseURL}/admin/dashboard`)

        // The user name must be visible somewhere in the dashboard
        await expect(page.getByText(user.name, { exact: false })).toBeVisible({ timeout: 10_000 })
    })

    test('login with invalid credentials shows inline error', async ({ page, request }) => {
        const user = await createUser(request)

        await page.goto(`${baseURL}/login`)
        await waitForApp(page)

        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill('wrong-password')
        await page.getByRole('button', { name: 'Entrar' }).click()

        // Must stay on /login
        await expect(page).toHaveURL(`${baseURL}/login`, { timeout: 10_000 })

        // An error message must be visible (either field-level or general)
        const errorVisible = await page.getByText(/credencial|contrasena|wrong|invalid|incorrecta|estas credenciales/i).isVisible()
            .catch(() => false)
        const generalErrorVisible = await page.locator('p.text-error, [class*="text-error"]').first().isVisible()
            .catch(() => false)
        expect(errorVisible || generalErrorVisible).toBe(true)
    })

    test('logout returns to login page', async ({ page, request }) => {
        const user = await createUser(request)

        // Log in first
        await page.goto(`${baseURL}/login`)
        await waitForApp(page)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Entrar' }).click()
        await page.waitForURL(`${baseURL}/admin/dashboard`, { timeout: 15_000 })

        // Click the logout button in the dashboard
        await page.getByRole('button', { name: 'Cerrar sesion' }).click()

        await page.waitForURL(`${baseURL}/login`, { timeout: 10_000 })
        await expect(page).toHaveURL(`${baseURL}/login`)
    })
})
