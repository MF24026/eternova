import { test, expect, type Page } from '@playwright/test'

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

async function waitForApp(page: Page): Promise<void> {
    await page.waitForFunction(
        () => {
            const el = document.querySelector('#app')
            return el !== null && el.children.length > 0
        },
        { timeout: 15_000 },
    )
}

test.describe('SPA routing', () => {
    test('home page renders without auth', async ({ page }) => {
        await page.goto(`${baseURL}/`)
        await waitForApp(page)

        // Both CTAs from HomePage.vue must be present
        await expect(page.getByRole('link', { name: 'Crear cuenta gratis' })).toBeVisible()
        await expect(page.getByRole('link', { name: 'Iniciar sesión' })).toBeVisible()
    })

    test('unauthenticated dashboard access redirects to login', async ({ page }) => {
        // Ensure no session cookies are present (fresh context)
        await page.goto(`${baseURL}/admin/dashboard`)
        await waitForApp(page)

        await page.waitForURL(/\/login/, { timeout: 10_000 })
        const url = new URL(page.url())
        expect(url.pathname).toBe('/login')
        expect(url.searchParams.get('redirect')).toBe('/admin/dashboard')
    })

    test('404 page renders for unknown route', async ({ page }) => {
        await page.goto(`${baseURL}/this-does-not-exist`)
        await waitForApp(page)

        await expect(page.getByText('404')).toBeVisible({ timeout: 5_000 })
        // "no encontrada" from the NotFoundPage h1
        await expect(page.getByText(/no encontrad/i)).toBeVisible()
    })
})
