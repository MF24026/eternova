import { test, expect, type Page, type APIRequestContext } from '@playwright/test'

// ---------------------------------------------------------------------------
// Helpers  (mirrors pos.spec.ts)
// ---------------------------------------------------------------------------

// Inside Sail containers the app is at localhost:80 (port 8080 is the host-side mapping).
// Override with PLAYWRIGHT_BASE_URL when running from outside the container.
const BASE_URL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost'

// Derive the eternova.localhost base for subdomain construction.
// e.g. BASE_URL = "http://localhost" → etBase = "eternova.localhost"
// Allows running with PLAYWRIGHT_BASE_URL=http://localhost:8080 on the host machine.
function buildTenantBase(slug: string): string {
    const base = BASE_URL.replace(/^https?:\/\//, '')  // strip scheme
    const host = base.split(':')[0]                     // strip port
    // Replace "localhost" label with "{slug}.eternova.localhost"
    const tenantHost = host === 'localhost'
        ? `${slug}.eternova.localhost`
        : `${slug}.eternova.${host}`
    return `http://${tenantHost}`
}

interface TestUser {
    email: string
    password: string
    name: string
}

interface ProvisionedTenant {
    user: TestUser
    token: string
    slug: string
    tenantBase: string
}

/**
 * Register a user, provision a tenant, and return the credentials + tenant info.
 * Uses the Sanctum token from registration for the tenant provisioning call so
 * those happen server-side without needing a tenant subdomain.
 */
async function registerAndProvisionTenant(request: APIRequestContext): Promise<ProvisionedTenant> {
    const ts = Date.now()
    const suffix = Math.random().toString(36).slice(2, 7)
    const slug = `taxtest${suffix}`

    const user: TestUser = {
        name: `Tax Tester ${ts}`,
        email: `tax-test-${ts}-${suffix}@example.com`,
        password: 'TaxTest123',
    }

    // Register → includes a personal access token we use for bootstrapping.
    const regRes = await request.post(`${BASE_URL}/api/v1/auth/register`, {
        data: user,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    })
    if (!regRes.ok()) {
        throw new Error(`register failed ${regRes.status()}: ${await regRes.text()}`)
    }
    const regBody = await regRes.json() as { plain_text_token: string }
    const token = regBody.plain_text_token

    // Provision a tenant (no tenant middleware — only auth:sanctum).
    const tenantRes = await request.post(`${BASE_URL}/api/v1/tenants`, {
        data: {
            slug,
            name: `Tax Test ${ts}`,
            business_name: `Tax Test Biz ${ts}`,
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
    if (!tenantRes.ok()) {
        throw new Error(`tenant provision failed ${tenantRes.status()}: ${await tenantRes.text()}`)
    }

    const tenantBase = buildTenantBase(slug)
    return { user, token, slug, tenantBase }
}

async function browserLogin(page: Page, tenantBase: string, user: TestUser): Promise<void> {
    await page.goto(`${tenantBase}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', user.email)
    await page.fill('input[type="password"]', user.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
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

async function navigateToPOS(page: Page, tenantBase: string): Promise<void> {
    await page.goto(`${tenantBase}/admin/pos`)
    await waitForApp(page)
    await page.waitForTimeout(1_200)
}

/**
 * In-page POST using relative URLs so the request goes to the current page's
 * origin (tenant subdomain). XSRF-TOKEN is read from the cookie and included
 * as X-XSRF-TOKEN. Must be called after browser login.
 */
async function apiPost(page: Page, path: string, data: unknown): Promise<unknown> {
    const result = await page.evaluate(
        async ([p, d]: [string, unknown]) => {
            const xsrfCookie = document.cookie
                .split(';')
                .map((c) => c.trim())
                .find((c) => c.startsWith('XSRF-TOKEN='))
                ?.slice('XSRF-TOKEN='.length) ?? ''

            const r = await fetch(p, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(xsrfCookie),
                },
                credentials: 'include',
                body: JSON.stringify(d),
            })
            const body = await r.json()
            return { ok: r.ok, status: r.status, body }
        },
        [path, data] as [string, unknown],
    )

    const res = result as { ok: boolean; status: number; body: unknown }
    if (!res.ok) {
        throw new Error(`POST ${path} failed (${res.status}): ${JSON.stringify(res.body)}`)
    }
    return res.body
}

async function apiGet(page: Page, path: string): Promise<unknown> {
    const result = await page.evaluate(async (p: string) => {
        const r = await fetch(p, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'include',
        })
        const body = await r.json()
        return { ok: r.ok, status: r.status, body }
    }, path)

    const res = result as { ok: boolean; status: number; body: unknown }
    if (!res.ok) {
        throw new Error(`GET ${path} failed (${res.status}): ${JSON.stringify(res.body)}`)
    }
    return res.body
}

interface BranchData { id: string; is_main: boolean }
interface VariantData { id: number; price_cents: number }
interface ProductData { id: number; variants: VariantData[] }

async function getMainBranchId(page: Page): Promise<string> {
    const body = await apiGet(page, '/api/v1/branches') as { data: BranchData[] }
    const main = body.data.find((b) => b.is_main) ?? body.data[0]
    if (!main) throw new Error('No branch found for tenant')
    return main.id
}

async function seedProduct(page: Page, branchId: string, priceCents: number): Promise<void> {
    const ts = Date.now()

    const productBody = await apiPost(page, '/api/v1/products', {
        name: `Tax Demo ${ts}`,
        base_price_cents: priceCents,
        is_active: true,
        variants: [{ price_cents: priceCents, options: {} }],
    }) as { data: ProductData }

    const variantId = productBody.data.variants[0]?.id
    if (!variantId) throw new Error('Product created without a variant')

    await apiPost(page, '/api/v1/inventory/movements', {
        branch_id: branchId,
        product_variant_id: variantId,
        type: 'entry',
        quantity: 10,
        notes: 'e2e tax test seed',
    })
}

async function setTaxConfig(
    page: Page,
    { enabled, rateBps, pricesIncludeTax }: { enabled: boolean; rateBps: number; pricesIncludeTax: boolean },
): Promise<void> {
    await apiPost(page, '/api/v1/settings/tax', {
        enabled,
        rate_bps: rateBps,
        prices_include_tax: pricesIncludeTax,
        id_label: null,
        id_number: null,
    })
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test.describe('POS live IVA preview', () => {
    // The inline cart is only visible on desktop (lg+). Mobile uses the bottom-bar
    // bottom-sheet — unrelated layout; skip to keep this spec focused on tax math.
    test.beforeEach(async ({ }, testInfo) => {
        test.skip(testInfo.project.name === 'chromium-mobile', 'desktop cart panel only')
    })

    test('exclusive 13% IVA: cart shows IVA 13.00 and Total 113.00, receipt confirms IVA line', async ({
        page,
        request,
    }) => {
        const { user, tenantBase } = await registerAndProvisionTenant(request)
        await browserLogin(page, tenantBase, user)

        const branchId = await getMainBranchId(page)
        // Item at $100.00 (10 000 cents) + exclusive 13% → IVA $13.00, Total $113.00
        await seedProduct(page, branchId, 10_000)
        await setTaxConfig(page, { enabled: true, rateBps: 1300, pricesIncludeTax: false })

        await navigateToPOS(page, tenantBase)

        await page.waitForSelector('.product-tile:not([disabled])', { timeout: 15_000 })
        await page.locator('.product-tile:not([disabled])').first().click()
        await page.waitForTimeout(400)

        const ivaRow = page.locator('[data-testid="pos-cart-iva-row"]')
        await expect(ivaRow).toBeVisible()

        const ivaAmount = page.locator('[data-testid="pos-cart-iva-amount"]')
        await expect(ivaAmount).toContainText('13')

        const totalAmount = page.locator('[data-testid="pos-cart-total-amount"]')
        await expect(totalAmount).toContainText('113')

        // Checkout → receipt must show IVA line with 13.
        await expect(page.locator('[data-testid="pos-checkout-btn"]')).toBeEnabled()
        await page.locator('[data-testid="pos-checkout-btn"]').click()

        const receiptPanel = page.locator('[data-testid="pos-receipt-panel"]')
        await expect(receiptPanel).toBeVisible({ timeout: 15_000 })
        // Exact match prevents collision with product names that contain "IVA".
        await expect(receiptPanel.getByText('IVA', { exact: true })).toBeVisible()
        await expect(receiptPanel).toContainText('13')
    })

    test('inclusive 13% IVA: 113.00-priced item keeps Total at 113.00 and breaks out 13.00 IVA', async ({
        page,
        request,
    }) => {
        const { user, tenantBase } = await registerAndProvisionTenant(request)
        await browserLogin(page, tenantBase, user)

        const branchId = await getMainBranchId(page)
        // Item at $113.00 (11 300 cents), inclusive 13% → extracted IVA $13.00, Total $113.00
        await seedProduct(page, branchId, 11_300)
        await setTaxConfig(page, { enabled: true, rateBps: 1300, pricesIncludeTax: true })

        await navigateToPOS(page, tenantBase)

        await page.waitForSelector('.product-tile:not([disabled])', { timeout: 15_000 })
        await page.locator('.product-tile:not([disabled])').first().click()
        await page.waitForTimeout(400)

        const ivaRow = page.locator('[data-testid="pos-cart-iva-row"]')
        await expect(ivaRow).toBeVisible()
        await expect(ivaRow).toContainText('incluido')

        const ivaAmount = page.locator('[data-testid="pos-cart-iva-amount"]')
        await expect(ivaAmount).toContainText('13')

        // Total stays at 113.00 — inclusive pricing, tax already in the item price.
        const totalAmount = page.locator('[data-testid="pos-cart-total-amount"]')
        await expect(totalAmount).toContainText('113')
    })
})
