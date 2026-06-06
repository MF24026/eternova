import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8080'

interface TestCredentials {
    email: string
    password: string
}

async function loginAsAdmin(request: APIRequestContext, page: Page): Promise<TestCredentials> {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 6)
    const credentials: TestCredentials = {
        email: `inventory-test-${ts}-${suffix}@example.com`,
        password: 'password123',
    }

    const res = await request.post(`${BASE_URL}/api/v1/auth/register`, {
        data: { name: 'Inventory Tester', ...credentials },
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })

    if (!res.ok()) {
        const body = await res.text()
        throw new Error(`register failed ${res.status()}: ${body}`)
    }

    // Authenticate through the UI so cookies and Pinia state are set correctly.
    await page.goto(`${BASE_URL}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', credentials.email)
    await page.fill('input[type="password"]', credentials.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })

    return credentials
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

test.describe('Admin inventory page', () => {
    test('inventory page loads after login and shows the toolbar', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory`)
        await waitForApp(page)
        await page.waitForTimeout(800)

        expect(page.url()).toContain('/admin/inventory')

        // Page title should be set by onMounted.
        await expect(page).toHaveTitle(/Inventario/)

        // Toolbar elements must be visible.
        await expect(page.locator('input[placeholder*="Buscar"]').first()).toBeVisible()
        await expect(page.getByText('Registrar movimiento').first()).toBeVisible()
        await expect(page.getByText('Transferencia').first()).toBeVisible()
    })

    test('inventory list renders rows or empty state — never crashes', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory`)
        await waitForApp(page)

        // Wait for the loading spinner to disappear.
        await page.waitForFunction(
            () => document.querySelector('[role="status"]') === null,
            { timeout: 10_000 },
        ).catch(() => { /* loading may already be done */ })

        await page.waitForTimeout(600)

        // Either a table row or an empty state should be visible — no crash.
        const tableRows = page.locator('table tbody tr')
        const emptyState = page.getByText('Sin registros de inventario')

        const rowCount = await tableRows.count()
        const emptyVisible = await emptyState.isVisible().catch(() => false)

        expect(rowCount > 0 || emptyVisible).toBeTruthy()
    })

    test('filter chips toggle correctly without crashing', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory`)
        await waitForApp(page)
        await page.waitForTimeout(600)

        const lowStockButton = page.getByRole('button', { name: 'Stock bajo' })
        await expect(lowStockButton).toBeVisible()

        // Click "Stock bajo" to activate filter.
        await lowStockButton.click()
        await page.waitForTimeout(400)

        // Click again to deactivate.
        await lowStockButton.click()
        await page.waitForTimeout(400)

        // No error modal or crash.
        const errorText = page.getByText('Error', { exact: false })
        expect(await errorText.count()).toBe(0)
    })

    test('AdjustStockSlideover opens when Registrar movimiento button is clicked', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory`)
        await waitForApp(page)
        await page.waitForTimeout(600)

        const openButton = page.getByRole('button', { name: 'Registrar movimiento' }).first()
        await expect(openButton).toBeVisible()
        await openButton.click()

        // Slideover panel should appear with the correct title.
        await expect(page.getByRole('dialog', { name: 'Registrar movimiento' })).toBeVisible({ timeout: 3_000 })

        // Movement type buttons should be present.
        await expect(page.getByRole('button', { name: 'Entrada' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Salida' })).toBeVisible()
        await expect(page.getByRole('button', { name: 'Ajuste' })).toBeVisible()
    })

    test('AdjustStockSlideover shows validation errors when submitted empty', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory`)
        await waitForApp(page)
        await page.waitForTimeout(600)

        // Open slideover.
        await page.getByRole('button', { name: 'Registrar movimiento' }).first().click()
        await expect(page.getByRole('dialog', { name: 'Registrar movimiento' })).toBeVisible({ timeout: 3_000 })

        // Clear the quantity field and submit.
        const quantityInput = page.locator('input[type="number"]').last()
        await quantityInput.fill('0')
        await page.getByRole('button', { name: 'Confirmar' }).click()

        // A validation message should appear.
        await page.waitForTimeout(400)
        const errorMessages = page.locator('.text-error')
        expect(await errorMessages.count()).toBeGreaterThan(0)
    })

    test('TransferStockSlideover opens and validates same-branch selection', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory`)
        await waitForApp(page)
        await page.waitForTimeout(600)

        // Open transfer slideover from the toolbar.
        await page.getByRole('button', { name: 'Transferencia' }).click()
        await expect(page.getByRole('dialog', { name: 'Transferir stock' })).toBeVisible({ timeout: 3_000 })

        // Select the same branch for both from and to.
        const selects = page.locator('select')
        const count = await selects.count()
        if (count >= 2) {
            // Select "Sucursal Principal" for from.
            await selects.nth(0).selectOption({ index: 1 }).catch(() => {
                /* skip if only placeholder */
            })
            // Select the same for to (same value = validation error).
            await selects.nth(1).selectOption({ index: 1 }).catch(() => {
                /* skip if only placeholder */
            })
        }

        // Fill quantity.
        await page.locator('input[type="number"]').first().fill('1')
        await page.getByRole('button', { name: 'Transferir' }).click()
        await page.waitForTimeout(400)

        // Expect a validation error (same branch).
        const errorMessages = page.locator('.text-error')
        expect(await errorMessages.count()).toBeGreaterThan(0)
    })

    test('movements page is reachable via the link in the KPI strip', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory`)
        await waitForApp(page)
        await page.waitForTimeout(600)

        // Click the ChevronRight link in the "Movimientos" KPI card.
        const movLink = page.locator('a[href="/admin/inventory/movements"]').first()
        await expect(movLink).toBeVisible()
        await movLink.click()

        await page.waitForURL('**/admin/inventory/movements', { timeout: 8_000 })
        expect(page.url()).toContain('/admin/inventory/movements')

        await expect(page).toHaveTitle(/Movimientos/)
    })

    test('movements page renders filter bar and table without crashing', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory/movements`)
        await waitForApp(page)
        await page.waitForTimeout(800)

        expect(page.url()).toContain('/admin/inventory/movements')

        // Filter controls.
        await expect(page.locator('select').first()).toBeVisible()

        // Table should exist (even if empty).
        const table = page.locator('table')
        expect(await table.count()).toBeGreaterThan(0)
    })

    test('movements page back-link navigates to inventory', async ({ page, request }) => {
        await loginAsAdmin(request, page)

        await page.goto(`${BASE_URL}/admin/inventory/movements`)
        await waitForApp(page)
        await page.waitForTimeout(600)

        await page.getByRole('link', { name: 'Inventario' }).click()
        await page.waitForURL('**/admin/inventory', { timeout: 8_000 })

        expect(page.url()).toContain('/admin/inventory')
        expect(page.url()).not.toContain('/movements')
    })
})
