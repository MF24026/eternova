import { test, expect, type Page } from '@playwright/test'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

function freshUser() {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    return {
        name: `Nuevo Usuario ${ts}`,
        email: `signup-${ts}-${suffix}@example.com`,
        password: 'password123',
    }
}

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

test.describe('Signup flow', () => {
    test('signup creates account and lands on dashboard', async ({ page }) => {
        const user = freshUser()

        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        await page.getByLabel('Nombre completo').fill(user.name)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Crear cuenta' }).click()

        await page.waitForURL(`${baseURL}/admin/dashboard`, { timeout: 20_000 })
        await expect(page).toHaveURL(`${baseURL}/admin/dashboard`)
    })

    test('signup rejects weak password with inline error', async ({ page }) => {
        const user = freshUser()

        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        await page.getByLabel('Nombre completo').fill(user.name)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill('123') // too short
        await page.getByRole('button', { name: 'Crear cuenta' }).click()

        // Must stay on /signup with an error visible
        await expect(page).toHaveURL(`${baseURL}/signup`, { timeout: 10_000 })

        const hasError = await page.locator('p.text-error, [class*="text-error"], input[class*="ring-error"]').first().isVisible()
            .catch(() => false)
        expect(hasError).toBe(true)
    })
})
