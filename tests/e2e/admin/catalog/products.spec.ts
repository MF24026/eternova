import { test, expect, type APIRequestContext } from '@playwright/test'
import { BASE_URL as baseURL, tenantBaseURL } from '../../support/env'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

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

interface Product {
    id: number
    name: string
    slug: string
    base_price_cents: number
}

async function createUser(request: APIRequestContext): Promise<CreatedUser> {
    const ts = Date.now()
    const user = {
        name: `Prod Owner ${ts}`,
        email: `prod-owner-${ts}@example.com`,
        password: 'ProdPassword1',
    }

    const res = await request.post(`${apiBase}/auth/register`, {
        data: user,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })
    if (!res.ok()) throw new Error(`register failed: ${res.status()} ${await res.text()}`)

    const body = await res.json()
    return { ...user, token: body.plain_text_token as string }
}

async function createTenant(request: APIRequestContext, user: CreatedUser): Promise<CreatedTenant> {
    const ts = Date.now()
    const slug = `prod-e2e-${ts}`

    const res = await request.post(`${apiBase}/tenants`, {
        data: {
            name: `Prod Test Tenant ${ts}`,
            slug,
            email: user.email,
            country_code: 'SV',
            currency: 'USD',
            timezone: 'America/El_Salvador',
        },
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${user.token}`,
        },
    })
    if (!res.ok()) throw new Error(`create tenant failed: ${res.status()} ${await res.text()}`)

    const body = await res.json()
    return { id: body.data.id as string, slug }
}

function tenantApiBase(tenant: CreatedTenant): string {
    return `${tenantBaseURL(tenant.slug)}/api/v1`
}

async function createProduct(
    request: APIRequestContext,
    user: CreatedUser,
    tenant: CreatedTenant,
    data: Partial<{ name: string; base_price_cents: number; sku_root: string; options: unknown[] }> = {}
): Promise<Product> {
    const api = tenantApiBase(tenant)
    const res = await request.post(`${api}/products`, {
        data: {
            name: data.name ?? `Test Product ${Date.now()}`,
            base_price_cents: data.base_price_cents ?? 5000,
            sku_root: data.sku_root ?? 'TEST',
            is_active: true,
            ...(data.options ? { options: data.options } : {}),
        },
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            Authorization: `Bearer ${user.token}`,
        },
    })
    if (!res.ok()) throw new Error(`create product failed: ${res.status()} ${await res.text()}`)

    const body = await res.json()
    return body.data as Product
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('Products admin CRUD', () => {
    test('create product with 2 options generates 4 variants in UI', async ({ page, request }) => {
        const user   = await createUser(request)
        const tenant = await createTenant(request, user)
        const tenantBase = tenantBaseURL(tenant.slug)

        // Login via API to get session cookie
        await page.goto(`${tenantBase}/login`)
        await page.waitForLoadState('networkidle')

        await page.getByLabel('Email').fill(user.email)
        await page.getByLabel('Password').fill(user.password)
        await page.getByRole('button', { name: /iniciar sesion/i }).click()

        await page.waitForURL('**/admin/dashboard', { timeout: 10_000 })

        // Navigate to new product form
        await page.goto(`${tenantBase}/admin/products/new`)
        await page.waitForLoadState('networkidle')

        // Fill basic info
        await page.getByLabel('Nombre del producto').fill('Rosa Bicolor E2E')
        await page.getByLabel('Precio base').fill('50.00')
        await page.getByLabel('SKU raiz').fill('ROSA-BC')

        // Switch to variants tab
        await page.getByRole('button', { name: 'Opciones y variantes' }).click()

        // Add first option
        await page.getByRole('button', { name: 'Agregar opcion' }).click()

        const optionNameInputs = page.locator('input[placeholder*="Nombre de opcion"]')
        await optionNameInputs.nth(0).fill('Color')

        const valueInputs = page.locator('input[placeholder*="Agregar valor"]')
        await valueInputs.nth(0).fill('Rojo')
        await page.getByRole('button', { name: 'Agregar' }).nth(0).click()

        await valueInputs.nth(0).fill('Verde')
        await page.getByRole('button', { name: 'Agregar' }).nth(0).click()

        // Add second option
        await page.getByRole('button', { name: 'Agregar opcion' }).click()

        await optionNameInputs.nth(1).fill('Tamano')

        await valueInputs.nth(1).fill('S')
        await page.getByRole('button', { name: 'Agregar' }).nth(1).click()

        await valueInputs.nth(1).fill('M')
        await page.getByRole('button', { name: 'Agregar' }).nth(1).click()

        // Verify 4 rows in the variant table
        const variantRows = page.locator('table tbody tr')
        await expect(variantRows).toHaveCount(4)

        // Save
        await page.getByRole('button', { name: /guardar producto/i }).click()

        // Should redirect to edit page
        await page.waitForURL('**/edit', { timeout: 10_000 })

        // Verify variants persisted
        await page.getByRole('button', { name: 'Opciones y variantes' }).click()
        const savedRows = page.locator('table tbody tr')
        await expect(savedRows).toHaveCount(4)
    })

    test('edit product name and verify update persists', async ({ page, request }) => {
        const user    = await createUser(request)
        const tenant  = await createTenant(request, user)
        const product = await createProduct(request, user, tenant, { name: 'Original Name' })
        const tenantBase = tenantBaseURL(tenant.slug)

        await page.goto(`${tenantBase}/login`)
        await page.waitForLoadState('networkidle')

        await page.getByLabel('Email').fill(user.email)
        await page.getByLabel('Password').fill(user.password)
        await page.getByRole('button', { name: /iniciar sesion/i }).click()
        await page.waitForURL('**/admin/dashboard', { timeout: 10_000 })

        await page.goto(`${tenantBase}/admin/products/${product.id}/edit`)
        await page.waitForLoadState('networkidle')

        // Change name
        const nameInput = page.getByLabel('Nombre del producto')
        await nameInput.clear()
        await nameInput.fill('Updated Name E2E')

        await page.getByRole('button', { name: /guardar producto/i }).click()

        // Verify API has the update
        const api = tenantApiBase(tenant)
        const res = await request.get(`${api}/products/${product.id}`, {
            headers: {
                Accept: 'application/json',
                Authorization: `Bearer ${user.token}`,
            },
        })
        const body = await res.json()
        expect(body.data.name).toBe('Updated Name E2E')
    })

    test('search filter applies in list page', async ({ page, request }) => {
        const user   = await createUser(request)
        const tenant = await createTenant(request, user)
        await createProduct(request, user, tenant, { name: 'Rosa Eterna E2E Search' })
        await createProduct(request, user, tenant, { name: 'Peluche Distinto E2E' })
        const tenantBase = tenantBaseURL(tenant.slug)

        await page.goto(`${tenantBase}/login`)
        await page.waitForLoadState('networkidle')

        await page.getByLabel('Email').fill(user.email)
        await page.getByLabel('Password').fill(user.password)
        await page.getByRole('button', { name: /iniciar sesion/i }).click()
        await page.waitForURL('**/admin/dashboard', { timeout: 10_000 })

        await page.goto(`${tenantBase}/admin/products`)
        await page.waitForLoadState('networkidle')

        // Wait for products to load
        await expect(page.getByText('Rosa Eterna E2E Search')).toBeVisible()
        await expect(page.getByText('Peluche Distinto E2E')).toBeVisible()

        // Search for "rosa"
        await page.getByPlaceholder('Buscar productos...').fill('Rosa')

        // Only Rosa should remain
        await expect(page.getByText('Rosa Eterna E2E Search')).toBeVisible({ timeout: 3_000 })
        await expect(page.getByText('Peluche Distinto E2E')).not.toBeVisible({ timeout: 3_000 })
    })

    test('soft delete product removes it from active list', async ({ page, request }) => {
        const user    = await createUser(request)
        const tenant  = await createTenant(request, user)
        await createProduct(request, user, tenant, { name: 'Borrar Este Producto E2E' })
        const tenantBase = tenantBaseURL(tenant.slug)

        await page.goto(`${tenantBase}/login`)
        await page.waitForLoadState('networkidle')

        await page.getByLabel('Email').fill(user.email)
        await page.getByLabel('Password').fill(user.password)
        await page.getByRole('button', { name: /iniciar sesion/i }).click()
        await page.waitForURL('**/admin/dashboard', { timeout: 10_000 })

        await page.goto(`${tenantBase}/admin/products`)
        await page.waitForLoadState('networkidle')

        // Product is visible in active list
        await expect(page.getByText('Borrar Este Producto E2E')).toBeVisible()

        // Click archive button -> styled confirm dialog -> accept
        await page.getByRole('button', { name: 'Archivar' }).first().click()
        await page.locator('[data-testid="confirm-accept"]').click()

        // Wait for list refresh — product should disappear from active filter
        await expect(page.getByText('Borrar Este Producto E2E')).not.toBeVisible({ timeout: 5_000 })

        // Switch to archived filter
        await page.getByText('Archivados').click()
        await expect(page.getByText('Borrar Este Producto E2E')).toBeVisible({ timeout: 5_000 })
    })

    test('validation error shows inline on duplicate slug', async ({ page, request }) => {
        const user    = await createUser(request)
        const tenant  = await createTenant(request, user)
        // Create first product to occupy the slug
        await createProduct(request, user, tenant, { name: 'Slug Dupe Test' })
        const tenantBase = tenantBaseURL(tenant.slug)

        await page.goto(`${tenantBase}/login`)
        await page.waitForLoadState('networkidle')

        await page.getByLabel('Email').fill(user.email)
        await page.getByLabel('Password').fill(user.password)
        await page.getByRole('button', { name: /iniciar sesion/i }).click()
        await page.waitForURL('**/admin/dashboard', { timeout: 10_000 })

        await page.goto(`${tenantBase}/admin/products/new`)
        await page.waitForLoadState('networkidle')

        // Switch to SEO tab and fill duplicate slug
        await page.getByRole('button', { name: 'SEO' }).click()
        await page.getByLabel('Slug (URL)').fill('slug-dupe-test')

        // Fill required basics
        await page.getByRole('button', { name: 'Datos basicos' }).click()
        await page.getByLabel('Nombre del producto').fill('Another Product')
        await page.getByLabel('Precio base').fill('10.00')

        await page.getByRole('button', { name: /guardar producto/i }).click()

        // Should show 422 inline error (slug unique rule) — auto-disambiguation
        // means the server may create with "-2", OR return 422 if we provide explicit duplicate.
        // We provided the slug explicitly, so it WILL 422.
        await expect(page.locator('[class*="error"]').or(page.getByText(/slug/i))).toBeVisible({ timeout: 5_000 })
    })
})
