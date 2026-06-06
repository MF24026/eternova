import { test, expect, type APIRequestContext, type Page } from '@playwright/test'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'
const apiBase = `${baseURL}/api/v1`

interface CreatedUser {
    name: string
    email: string
    password: string
    token: string
}

interface CreatedTenant {
    id: string
    slug: string
}

interface Category {
    id: number
    name: string
    slug: string
}

/**
 * Register a fresh user and return their credentials + bearer token.
 */
async function createUser(request: APIRequestContext): Promise<CreatedUser> {
    const ts = Date.now()
    const user = {
        name: `Cat Owner ${ts}`,
        email: `cat-owner-${ts}@example.com`,
        password: 'CatPassword1',
    }

    const res = await request.post(`${apiBase}/auth/register`, {
        data: user,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })
    if (!res.ok()) throw new Error(`register failed: ${res.status()} ${await res.text()}`)

    const body = await res.json()
    return { ...user, token: body.plain_text_token as string }
}

/**
 * Provision a tenant for the user and make them the owner.
 */
async function createTenant(request: APIRequestContext, token: string, suffix: string): Promise<CreatedTenant> {
    const res = await request.post(`${apiBase}/tenants`, {
        data: {
            slug: `e2e-cat-${suffix}`,
            name: `E2E Cat Shop ${suffix}`,
            business_name: `E2E Cat Shop ${suffix}`,
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
    if (!res.ok()) throw new Error(`tenant create failed: ${res.status()} ${await res.text()}`)

    const body = await res.json()
    return { id: body.data.id as string, slug: body.data.slug as string }
}

/**
 * Create a category via the API, scoped to a tenant.
 */
async function createCategory(
    request: APIRequestContext,
    token: string,
    tenantSlug: string,
    data: { name: string; slug?: string; is_active?: boolean },
): Promise<Category> {
    const res = await request.post(`${tenantApiBase(tenantSlug)}/categories`, {
        data,
        headers: authHeaders(token),
    })
    if (!res.ok()) throw new Error(`category create failed: ${res.status()} ${await res.text()}`)
    const body = await res.json()
    return body.data as Category
}

function tenantApiBase(slug: string): string {
    return `http://${slug}.eternova.app/api/v1`
}

function authHeaders(token: string): Record<string, string> {
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
    }
}

/**
 * Wait for the Vue SPA to fully mount.
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

/**
 * Login via the UI and navigate to admin categories.
 */
async function loginAndGoToCategories(
    page: Page,
    email: string,
    password: string,
    tenantSlug: string,
): Promise<void> {
    await page.goto(`http://${tenantSlug}.eternova.app/login`)
    await waitForApp(page)

    await page.getByLabel(/correo|email/i).fill(email)
    await page.getByLabel(/contrasena|password/i).fill(password)
    await page.getByRole('button', { name: /iniciar|login/i }).click()

    await page.waitForURL(/admin|dashboard/, { timeout: 10_000 })
    await page.goto(`http://${tenantSlug}.eternova.app/admin/catalog/categories`)
    await waitForApp(page)
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Categories admin', () => {
    let user: CreatedUser
    let tenant: CreatedTenant

    test.beforeAll(async ({ request }) => {
        const suffix = Date.now().toString().slice(-6)
        user = await createUser(request)
        tenant = await createTenant(request, user.token, suffix)
    })

    test('create a category from the slideover form', async ({ page, request }) => {
        // Seed: no categories yet
        await loginAndGoToCategories(page, user.email, user.password, tenant.slug)

        // Click "Nueva" button to open the slideover
        await page.getByRole('button', { name: 'Nueva' }).click()
        await expect(page.getByRole('heading', { name: /nueva categoria/i })).toBeVisible()

        // Fill the form
        await page.getByLabel(/nombre/i).fill('Rosas Eternas')
        await page.getByRole('button', { name: /crear categoria/i }).click()

        // Verify in list
        await expect(page.getByText('Rosas Eternas')).toBeVisible({ timeout: 5_000 })
    })

    test('edit an existing category', async ({ page, request }) => {
        const category = await createCategory(request, user.token, tenant.slug, {
            name: 'Categoria Editable',
            slug: `editable-${Date.now()}`,
        })

        await loginAndGoToCategories(page, user.email, user.password, tenant.slug)

        // Click the edit button on the category
        await page.getByRole('button', { name: /editar/i }).first().click()
        await expect(page.getByRole('heading', { name: /editar categoria/i })).toBeVisible()

        // Change the name
        const nameInput = page.getByLabel(/nombre/i)
        await nameInput.clear()
        await nameInput.fill('Categoria Editada')
        await page.getByRole('button', { name: /guardar cambios/i }).click()

        await expect(page.getByText('Categoria Editada')).toBeVisible({ timeout: 5_000 })
    })

    test('search filters the category list', async ({ page, request }) => {
        const suffix = Date.now()
        await createCategory(request, user.token, tenant.slug, {
            name: `Peluches Especiales ${suffix}`,
            slug: `peluches-${suffix}`,
        })
        await createCategory(request, user.token, tenant.slug, {
            name: `Globos ${suffix}`,
            slug: `globos-${suffix}`,
        })

        await loginAndGoToCategories(page, user.email, user.password, tenant.slug)

        // Type in the search box
        await page.getByPlaceholder(/buscar/i).fill('Peluches')

        // Only the matching category should be visible
        await expect(page.getByText(`Peluches Especiales ${suffix}`)).toBeVisible({ timeout: 3_000 })
        await expect(page.getByText(`Globos ${suffix}`)).not.toBeVisible()
    })

    test('soft delete moves category to archived view and restore brings it back', async ({ page, request }) => {
        const suffix = Date.now()
        await createCategory(request, user.token, tenant.slug, {
            name: `Temporal ${suffix}`,
            slug: `temporal-${suffix}`,
        })

        await loginAndGoToCategories(page, user.email, user.password, tenant.slug)

        // Verify category appears in active list
        await expect(page.getByText(`Temporal ${suffix}`)).toBeVisible()

        // Delete it (archive)
        await page.getByRole('button', { name: /archivar/i }).first().click()
        await page.getByRole('button', { name: /ok|si|aceptar/i }).click().catch(() => {
            // Some browsers handle confirm() natively — ok if the button doesn't appear
        })

        // Switch to archived filter
        await page.getByRole('button', { name: /archivadas/i }).click()
        await expect(page.getByText(`Temporal ${suffix}`)).toBeVisible({ timeout: 3_000 })

        // Restore
        await page.getByRole('button', { name: /restaurar/i }).first().click()

        // Back to active view — should appear again
        await page.getByRole('button', { name: /activas/i }).click()
        await expect(page.getByText(`Temporal ${suffix}`)).toBeVisible({ timeout: 3_000 })
    })

    test('validation error shown inline for duplicate slug', async ({ page, request }) => {
        const slug = `dupslug-${Date.now()}`
        await createCategory(request, user.token, tenant.slug, {
            name: 'Primera',
            slug,
        })

        await loginAndGoToCategories(page, user.email, user.password, tenant.slug)

        // Try to create another with the same slug
        await page.getByRole('button', { name: 'Nueva' }).click()
        await page.getByLabel(/nombre/i).fill('Segunda')
        await page.getByLabel(/slug/i).fill(slug)
        await page.getByRole('button', { name: /crear categoria/i }).click()

        // Expect an inline validation error
        await expect(page.getByText(/ya ha sido tomado|already been taken|duplicate/i)).toBeVisible({
            timeout: 3_000,
        })
    })
})
