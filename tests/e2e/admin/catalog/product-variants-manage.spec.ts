import { test, expect, type Page } from '@playwright/test'

/**
 * Product edit-mode variant management overlay — add and remove variants on an
 * existing product (edit SKU/price is exercised in the manual QA). Round-trips
 * (add a uniquely-named variant, then delete it) so it leaves the demo data as-is.
 *
 * Seeded rosa-eterna tenant on its subdomain (only Chromium resolves it).
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('#password', OWNER.password)
    await page.click('button.auth-submit')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

interface Target { id: number; axisName: string }

/** Find a product whose variants carry an option axis (Color / Talla / ...). */
async function findOptionProduct(page: Page): Promise<Target | null> {
    return page.evaluate(async () => {
        const opts = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'include' as const }
        const list = (await (await fetch('/api/v1/products?per_page=100', opts)).json()).data as Array<{ id: number }>
        for (const p of list) {
            const detail = (await (await fetch(`/api/v1/products/${p.id}`, opts)).json()).data as {
                variants?: Array<{ options: Record<string, string> }>
            }
            const withOptions = (detail.variants ?? []).find((v) => Object.keys(v.options).length > 0)
            if (withOptions) return { id: p.id, axisName: Object.keys(withOptions.options)[0] }
        }
        return null
    })
}

test('manage variants: add then remove in product edit mode', async ({ page }) => {
    await login(page)

    const target = await findOptionProduct(page)
    expect(target, 'a seeded product with option-bearing variants').toBeTruthy()

    await page.goto(`${TENANT_BASE}/admin/products/${target!.id}/edit`, { waitUntil: 'domcontentloaded' })

    // Open the variants tab, then the management overlay.
    await page.getByRole('button', { name: /opciones y variantes/i }).click()
    await page.locator('[data-testid="btn-manage-variants"]').click()

    const overlay = page.locator('[data-testid="product-variants-overlay"]')
    await expect(overlay).toBeVisible()

    const rows = overlay.locator('[data-testid^="pv-row-"]')
    const before = await rows.count()
    expect(before).toBeGreaterThan(0)

    // Per-branch stock: an existing variant shows a numeric availability cell.
    // rosa-eterna is single-branch, so the branch selector stays hidden.
    await expect(overlay.locator('[data-testid^="pv-stock-"]').first()).toHaveText(/\d/)
    await expect(overlay.locator('[data-testid="pv-branch-select"]')).toHaveCount(0)

    // Add a variant with a unique option value so it never collides.
    const uniqueValue = `E2E-${Date.now()}`
    await overlay.locator(`[data-testid="pv-new-option-${target!.axisName}"]`).fill(uniqueValue)
    await overlay.locator('[data-testid="pv-new-price"]').fill('9.99')
    await overlay.locator('[data-testid="pv-add"]').click()

    await expect(rows).toHaveCount(before + 1)
    await expect(overlay.getByText(`${target!.axisName}: ${uniqueValue}`)).toBeVisible()

    // Availability toggle (Shopify-style): flip the new variant off then back on.
    // A newly added variant starts available (aria-checked=true).
    const newRow = overlay.locator('[data-testid^="pv-row-"]', { hasText: uniqueValue })
    const toggle = newRow.locator('[data-testid^="pv-active-"]')
    await expect(toggle).toHaveAttribute('aria-checked', 'true')
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'false')
    await expect(newRow.getByText('No disponible')).toBeVisible()
    await toggle.click()
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    // Remove the variant we just added — inline confirm (arm, then confirm) so it
    // leaves the demo data as it was.
    const targetRow = overlay.locator('[data-testid^="pv-row-"]', { hasText: uniqueValue })
    await targetRow.locator('[data-testid^="pv-remove-"]').click()
    await targetRow.locator('[data-testid^="pv-confirm-remove-"]').click()

    await expect(rows).toHaveCount(before)
})
