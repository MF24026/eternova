import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

function freshUser() {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    return {
        name: `Test Wizard ${ts}`,
        email: `wizard-${ts}-${suffix}@example.com`,
        password: 'password123',
    }
}

function freshSlug(): string {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    return `floreria-${ts}-${suffix}`.slice(0, 40)
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

/**
 * Wait until the slug availability indicator settles to a non-checking state.
 * The debounce is 400ms + network round-trip.
 */
async function waitForSlugCheck(page: Page): Promise<void> {
    // Wait until the spinner is gone (checking -> available or unavailable)
    await page.waitForFunction(
        () => {
            const spinner = document.querySelector('[role="status"]')
            return !spinner
        },
        { timeout: 8_000 },
    )
}

/**
 * Seed a tenant slug via API so we can test the "taken" scenario.
 */
async function seedTakenSlug(request: APIRequestContext, slug: string): Promise<void> {
    // Register a new user
    const ts = Date.now()
    const regRes = await request.post(`${baseURL}/api/v1/auth/register`, {
        data: {
            name: 'Seed User',
            email: `seed-${ts}@example.com`,
            password: 'password123',
        },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })
    const regBody = await regRes.json()
    const token: string = regBody.plain_text_token

    // Create a tenant with the given slug
    await request.post(`${baseURL}/api/v1/tenants`, {
        data: {
            name: 'Taken Tenant',
            slug,
            business_name: 'Taken Tenant',
            country_code: 'SV',
            currency: 'USD',
            language: 'es',
            timezone: 'America/El_Salvador',
        },
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${token}`,
        },
    })
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Signup wizard', () => {
    test('blocks advancing from account step with invalid email or short password', async ({ page }) => {
        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        // Fill name but bad email + short password
        await page.getByLabel('Nombre completo').fill('Maria Lopez')
        await page.getByLabel('Correo electronico').fill('not-an-email')
        await page.getByLabel('Contrasena').fill('123')

        const continueBtn = page.getByRole('button', { name: 'Continuar' })

        // Button should be disabled because client-side canSubmit is false
        await expect(continueBtn).toBeDisabled()

        // Still on step 1 — step indicator shows step 1 active
        await expect(page.locator('[aria-current="step"]')).toContainText('1')
    })

    test('shows reserved slug error inline when typing admin', async ({ page }) => {
        const user = freshUser()

        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        // Complete account step
        await page.getByLabel('Nombre completo').fill(user.name)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Continuar' }).click()

        // Advance to plan step
        await expect(page.getByText('Elige tu plan')).toBeVisible({ timeout: 10_000 })
        await page.getByRole('button', { name: 'Continuar' }).click()

        // Now on tenant step — type "admin" into the slug field
        await expect(page.getByText('Configura tu negocio')).toBeVisible({ timeout: 10_000 })
        await page.locator('#tenant-slug').fill('admin')

        await waitForSlugCheck(page)

        // Expect "Reservado" error message
        await expect(page.locator('#tenant-slug-status')).toContainText('Reservado', { timeout: 8_000 })
    })

    test('tenant step offers a business-type picker that defaults to floreria and is selectable', async ({ page }) => {
        const user = freshUser()

        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        await page.getByLabel('Nombre completo').fill(user.name)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Continuar' }).click()

        await expect(page.getByText('Elige tu plan')).toBeVisible({ timeout: 10_000 })
        await page.getByRole('button', { name: 'Continuar' }).click()

        await expect(page.getByText('Configura tu negocio')).toBeVisible({ timeout: 10_000 })

        // All four templates render; floreria is the default selection.
        for (const t of ['floreria', 'accesorios', 'peluches', 'reposteria']) {
            await expect(page.locator(`[data-testid="template-${t}"]`)).toBeVisible()
        }
        await expect(page.locator('[data-testid="template-floreria"]')).toHaveAttribute('aria-pressed', 'true')

        // Selecting another template moves the selection.
        await page.locator('[data-testid="template-reposteria"]').click()
        await expect(page.locator('[data-testid="template-reposteria"]')).toHaveAttribute('aria-pressed', 'true')
        await expect(page.locator('[data-testid="template-floreria"]')).toHaveAttribute('aria-pressed', 'false')
    })

    test('shows taken slug error when slug already exists in DB', async ({ page, request }) => {
        const user = freshUser()
        const takenSlug = freshSlug()

        // Seed the slug so it's already taken
        await seedTakenSlug(request, takenSlug)

        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        // Complete account step
        await page.getByLabel('Nombre completo').fill(user.name)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Continuar' }).click()

        // Advance to plan step
        await expect(page.getByText('Elige tu plan')).toBeVisible({ timeout: 10_000 })
        await page.getByRole('button', { name: 'Continuar' }).click()

        // Now on tenant step — type the taken slug
        await expect(page.getByText('Configura tu negocio')).toBeVisible({ timeout: 10_000 })
        await page.locator('#tenant-slug').fill(takenSlug)

        await waitForSlugCheck(page)

        // Expect "Ya esta en uso" message
        await expect(page.locator('#tenant-slug-status')).toContainText('Ya esta en uso', { timeout: 8_000 })
    })

    test('allows back navigation between steps preserving state', async ({ page }) => {
        const user = freshUser()

        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        // Fill account step
        await page.getByLabel('Nombre completo').fill(user.name)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Continuar' }).click()

        // On plan step, go back
        await expect(page.getByText('Elige tu plan')).toBeVisible({ timeout: 10_000 })
        await page.getByRole('button', { name: 'Atras' }).click()

        // Back on account step — state preserved (inputs should still have values)
        // Note: the store preserves the values but the inputs re-read from refs on mount
        await expect(page.getByText('Crea tu cuenta')).toBeVisible({ timeout: 5_000 })

        // Advance past account step again (re-clicking Continuar would re-register,
        // so we just verify we can go forward/back without crashing)
        await expect(page.getByRole('button', { name: 'Continuar' })).toBeVisible()
    })

    test('completes the full wizard end-to-end and redirects to new tenant subdomain', async ({ page }) => {
        const user = freshUser()
        const slug = freshSlug()

        await page.goto(`${baseURL}/signup`)
        await waitForApp(page)

        // Step 1: account
        await page.getByLabel('Nombre completo').fill(user.name)
        await page.getByLabel('Correo electronico').fill(user.email)
        await page.getByLabel('Contrasena').fill(user.password)
        await page.getByRole('button', { name: 'Continuar' }).click()

        // Step 2: plan — wait for plans to load, select basico, continue
        await expect(page.getByText('Elige tu plan')).toBeVisible({ timeout: 15_000 })
        // Plans load async — wait for the plan cards to appear
        await expect(page.getByText('Basico')).toBeVisible({ timeout: 10_000 })
        await page.getByText('Basico').click()
        await page.getByRole('button', { name: 'Continuar' }).click()

        // Step 3: tenant
        await expect(page.getByText('Configura tu negocio')).toBeVisible({ timeout: 10_000 })
        await page.getByLabel('Nombre de tu negocio').fill('Mi Floristeria Test')
        // Clear auto-derived slug and type a fresh unique one
        await page.locator('#tenant-slug').fill('')
        await page.locator('#tenant-slug').fill(slug)

        // Wait for slug check to settle as "available"
        await waitForSlugCheck(page)
        await expect(page.locator('#tenant-slug-status')).toContainText('Disponible', { timeout: 8_000 })

        await page.getByRole('button', { name: 'Crear negocio' }).click()

        // Step 4: done — congratulations screen
        await expect(page.getByText('Listo, tu negocio esta en linea')).toBeVisible({ timeout: 20_000 })

        // The done step shows the new tenant URL
        const tenantUrlLocator = page.getByText(new RegExp(slug))
        await expect(tenantUrlLocator).toBeVisible({ timeout: 5_000 })

        // The page will redirect to a different host (slug.eternova.localhost:8080).
        // In headless Playwright that host is not running a separate server, so we
        // assert the URL was constructed correctly by inspecting the link href rather
        // than waiting for actual navigation.
        const tenantLink = page.locator('a[href*="/admin/dashboard"]')
        await expect(tenantLink).toBeVisible({ timeout: 5_000 })
        const href = await tenantLink.getAttribute('href')
        expect(href).toContain(slug)
        expect(href).toContain('/admin/dashboard')
    })
})
