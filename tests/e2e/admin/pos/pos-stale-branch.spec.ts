import { test, expect, type Page } from '@playwright/test'

/**
 * Regression for the QA-S4 blocker: the POS read branchId from localStorage
 * without validating it against the tenant's current branches. After a re-seed
 * (or a branch deletion) the stored ULID is stale, and /api/v1/pos/products 500s
 * with "The selected branch does not belong to this tenant" — the product grid
 * goes empty and the terminal is unusable.
 *
 * The fix validates the persisted branchId on mount and falls back to the
 * main/first branch when it is stale. This spec proves a poisoned localStorage
 * value self-heals.
 *
 * Desktop-only (the full grid + cart). Runs against the seeded demo tenant.
 */
const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const POS_STORAGE_KEY = 'eternova:pos:rosa-eterna'
const STALE_BRANCH_ID = '01ktmea8ft13ggedbjzd8yz5v4' // a ULID that does not belong to this tenant

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

test.describe('POS stale-branch recovery (QA-S4 regression)', () => {
    test.beforeEach(async ({ }, testInfo) => {
        test.skip(testInfo.project.name === 'chromium-mobile', 'desktop-only POS grid')
    })

    test('poisoned localStorage branchId self-heals and products still load', async ({ page }) => {
        await login(page)

        // Poison localStorage with a stale branch id, as a real session would have
        // after a re-seed. The store reads this key on mount.
        await page.evaluate(
            ({ key, branchId }) => {
                window.localStorage.setItem(
                    key,
                    JSON.stringify({ branchId, lines: [], paymentMethod: 'cash', customerId: null, notes: '' }),
                )
            },
            { key: POS_STORAGE_KEY, branchId: STALE_BRANCH_ID },
        )

        await page.goto(`${TENANT_BASE}/admin/pos`)

        // The fix must discard the stale id and resolve a valid branch → the product
        // grid loads. Before the fix this timed out (empty grid + 500 on products).
        await page.waitForSelector('.product-tile', { timeout: 15_000 })
        await expect(page.locator('.product-tile').first()).toBeVisible()

        // The persisted branchId must no longer be the stale value.
        const storedBranchId = await page.evaluate((key) => {
            const raw = window.localStorage.getItem(key)
            return raw ? (JSON.parse(raw) as { branchId?: string }).branchId ?? null : null
        }, POS_STORAGE_KEY)
        expect(storedBranchId).not.toBe(STALE_BRANCH_ID)
        expect(storedBranchId).toBeTruthy()
    })
})
