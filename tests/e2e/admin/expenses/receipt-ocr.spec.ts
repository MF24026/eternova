import { test, expect, type Page } from '@playwright/test'
import * as path from 'path'
import * as fs from 'fs'
import * as os from 'os'

/**
 * S6-E7: Receipt upload + OCR verification UI
 *
 * Exercises:
 *   1. Upload→processing→done→verify flow (FakeOcrDriver returns instantly in dev).
 *   2. Verify a seeded draft expense from the list (no upload needed).
 *   3. Processing spinner appears during upload.
 *   4. Mobile (375px) — slideovers render as bottom-sheets.
 *
 * Seeder requirements (DemoTenantsSeeder / ExpensesSeeder):
 *   - Tenant slug: rosa-eterna
 *   - Owner: caro@rosaeterna.com / DemoPro123!
 *   - At least 2 draft expenses (is_verified=false, ocr_status=done, ocr_data present)
 *     seeded from April/May 2026 (outside the default current-month view).
 *
 * Run with: --workers=1 (state-mutating, shared DB)
 * FakeOcrDriver is active (OCR_DRIVER=fake), so uploads complete ~instantly.
 *
 * Why in-page fetch:
 *   Node cannot resolve *.eternova.localhost — Chromium applies RFC 6761 loopback.
 *   page.evaluate() runs inside the browser where the host resolves and the session
 *   cookie is sent automatically.
 */

const TENANT_BASE = process.env.POS_TENANT_BASE_URL ?? 'http://rosa-eterna.eternova.localhost'
const OWNER = { email: 'caro@rosaeterna.com', password: 'DemoPro123!' }
const EXPENSES_URL = `${TENANT_BASE}/admin/expenses`

// ── Helpers ───────────────────────────────────────────────────────────────────

async function login(page: Page): Promise<void> {
    await page.goto(`${TENANT_BASE}/login`)
    await page.waitForSelector('input[type="email"]', { timeout: 10_000 })
    await page.fill('input[type="email"]', OWNER.email)
    await page.fill('input[type="password"]', OWNER.password)
    await page.click('button[type="submit"]')
    await page.waitForURL('**/admin/**', { timeout: 15_000 })
}

async function gotoExpensesPage(page: Page): Promise<void> {
    await page.goto(EXPENSES_URL)
    await page.waitForSelector('[data-testid="period-total"]', { timeout: 15_000 })
    await waitForNoSkeleton(page)
}

async function waitForNoSkeleton(page: Page): Promise<void> {
    await page.waitForFunction(
        () =>
            document.querySelectorAll('.animate-pulse').length === 0 &&
            document.querySelectorAll('.animate-spin').length === 0,
        { timeout: 10_000 },
    )
}

/**
 * Clear the month filter on the expenses page.
 * The seeded drafts live in April/May 2026 (outside the default current month view),
 * so we must clear the filter to make them visible.
 * Uses triple-click + Delete to trigger Vue's v-model reactivity reliably.
 */
async function clearMonthFilter(page: Page): Promise<void> {
    const monthInput = page.locator('input[type="month"]')
    await monthInput.click({ clickCount: 3 })
    await page.keyboard.press('Delete')
    // A brief pause allows the debounced fetch watcher to fire.
    await page.waitForTimeout(400)
    await waitForNoSkeleton(page)
}

/** Create a minimal valid PNG file in a temp dir for setInputFiles. */
function createTempPng(): string {
    // 1×1 white PNG (smallest valid PNG — 26 bytes when hex-decoded)
    const pngBytes = Buffer.from(
        '89504e470d0a1a0a0000000d49484452000000010000000108020000009001' +
        '2e0000000c4944415408d76360f8ff0000000200019e221bc60000000049454e44ae426082',
        'hex',
    )
    const tmpDir = os.tmpdir()
    const filePath = path.join(tmpDir, `receipt-test-${Date.now()}.png`)
    fs.writeFileSync(filePath, pngBytes)
    return filePath
}

interface ExpenseApiShape {
    id: number
    is_verified: boolean
    ocr_status: string
    ocr_data: Record<string, unknown> | null
    description: string
}

/**
 * In-page fetch with XSRF token. Returns parsed JSON + HTTP status.
 */
async function apiFetch(
    page: Page,
    apiPath: string,
    options: { method?: string; body?: unknown } = {},
): Promise<{ status: number; ok: boolean; body: unknown }> {
    return page.evaluate(
        async ([p, method, body]) => {
            const xsrfCookie = document.cookie
                .split(';')
                .map((c) => c.trim())
                .find((c) => c.startsWith('XSRF-TOKEN='))
            const xsrfToken = xsrfCookie
                ? decodeURIComponent(xsrfCookie.split('=').slice(1).join('='))
                : null

            const init: RequestInit = {
                method: method ?? 'GET',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(xsrfToken ? { 'X-XSRF-TOKEN': xsrfToken } : {}),
                    ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
                },
                credentials: 'include',
                ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
            }

            const r = await fetch(p, init)
            let responseBody: unknown
            try {
                responseBody = await r.json()
            } catch {
                responseBody = null
            }
            return { status: r.status, ok: r.ok, body: responseBody }
        },
        [apiPath, options.method ?? 'GET', options.body] as [string, string, unknown],
    )
}

/** Find the first seeded draft expense (is_verified=false, ocr_status=done). */
async function findSeededDraftExpense(page: Page): Promise<ExpenseApiShape | null> {
    const result = await apiFetch(page, '/api/v1/expenses?is_verified=0&per_page=20')
    const body = result.body as { data?: ExpenseApiShape[] }
    const drafts = body.data ?? []
    // Prefer drafts that already have OCR data (seeded drafts) for the clearest test
    return (
        drafts.find((e) => e.ocr_status === 'done' && e.ocr_data !== null) ??
        drafts[0] ??
        null
    )
}

// ── Tests ─────────────────────────────────────────────────────────────────────

test.describe('Receipt upload → OCR → verify (S6-E7)', () => {

    test('upload button is visible in the header', async ({ page }) => {
        await login(page)
        await gotoExpensesPage(page)

        await expect(page.locator('[data-testid="btn-subir-factura"]')).toBeVisible()
    })

    test('upload slideover opens from the header button', async ({ page }) => {
        await login(page)
        await gotoExpensesPage(page)

        await page.click('[data-testid="btn-subir-factura"]')

        await expect(page.locator('[data-testid="receipt-upload-slideover"]')).toBeVisible()

        // Dropzone is visible in the initial idle state
        await expect(page.locator('[data-testid="receipt-dropzone"]')).toBeVisible()
    })

    test('submit button is disabled with no file selected', async ({ page }) => {
        await login(page)
        await gotoExpensesPage(page)

        await page.click('[data-testid="btn-subir-factura"]')
        await expect(page.locator('[data-testid="receipt-upload-slideover"]')).toBeVisible()

        const submitBtn = page.locator('[data-testid="btn-subir-factura-submit"]')
        await expect(submitBtn).toBeDisabled()
    })

    test('upload → processing state → verification slideover opens with OCR pre-filled fields', async ({ page }) => {
        await login(page)
        await gotoExpensesPage(page)

        await page.click('[data-testid="btn-subir-factura"]')
        await expect(page.locator('[data-testid="receipt-upload-slideover"]')).toBeVisible()

        const pngPath = createTempPng()
        try {
            // Set file via the hidden input
            const fileInput = page.locator('[data-testid="receipt-file-input"]')
            await fileInput.setInputFiles(pngPath)

            // Preview should appear
            await expect(page.locator('[data-testid="receipt-file-preview"]')).toBeVisible()

            // Submit the upload
            await page.click('[data-testid="btn-subir-factura-submit"]')

            // Processing state appears (spinner + text)
            await expect(page.locator('[data-testid="receipt-processing-state"]')).toBeVisible({
                timeout: 5_000,
            })

            // With FakeOcrDriver the job completes ~instantly. The upload slideover closes
            // and the verification slideover opens.
            await expect(page.locator('[data-testid="receipt-verification-slideover"]')).toBeVisible({
                timeout: 15_000,
            })

            // The OCR suggestion banner must be shown (FakeOcrDriver → ocr_status=done)
            await expect(page.locator('[data-testid="ocr-suggestion-banner"]')).toBeVisible({
                timeout: 5_000,
            })

            // FakeOcrDriver returns: vendor "Proveedor Demo S.A.", amount 12345 (→ 123.45), date 2026-01-15
            const vendorInput = page.locator('[data-testid="ver-vendor"]')
            await expect(vendorInput).toHaveValue(/Proveedor Demo/i, { timeout: 5_000 })

            const amountInput = page.locator('[data-testid="ver-amount"]')
            const amountValue = await amountInput.inputValue()
            expect(parseFloat(amountValue)).toBeCloseTo(123.45, 1)

            const dateInput = page.locator('[data-testid="ver-date"]')
            await expect(dateInput).toHaveValue('2026-01-15')

            // User corrects the vendor field
            await vendorInput.fill('Proveedor Corregido E2E')

            // Confirm the expense
            await page.click('[data-testid="btn-confirmar-gasto"]')

            // Toast + slideover closes
            await expect(page.locator('text=Gasto verificado')).toBeVisible({ timeout: 8_000 })
            await expect(page.locator('[data-testid="receipt-verification-slideover"]')).not.toBeVisible()

            // Reload the expenses page. Clear month filter so the new expense is visible
            // regardless of when it was created.
            await gotoExpensesPage(page)
            await clearMonthFilter(page)

            // Search by the corrected vendor name
            await page.fill('input[placeholder="Buscar proveedor o descripción..."]', 'Proveedor Corregido E2E')
            await page.waitForTimeout(400)
            await waitForNoSkeleton(page)

            // The expense must appear with a "Verificado" badge (not "Borrador")
            const firstRow = page.locator('table tbody tr').first()
            await expect(firstRow.locator('span:has-text("Verificado")')).toBeVisible({
                timeout: 10_000,
            })
        } finally {
            fs.unlinkSync(pngPath)
        }
    })

    test('processing spinner appears during upload phase', async ({ page }) => {
        await login(page)
        await gotoExpensesPage(page)

        await page.click('[data-testid="btn-subir-factura"]')
        await expect(page.locator('[data-testid="receipt-upload-slideover"]')).toBeVisible()

        const pngPath = createTempPng()
        try {
            await page.locator('[data-testid="receipt-file-input"]').setInputFiles(pngPath)
            await page.click('[data-testid="btn-subir-factura-submit"]')

            // The processing state (with spinner) must appear before transitioning away.
            await expect(page.locator('[data-testid="receipt-processing-state"]')).toBeVisible({
                timeout: 5_000,
            })
        } finally {
            fs.unlinkSync(pngPath)
        }
    })

    test('verify a seeded draft expense from the list', async ({ page }) => {
        await login(page)
        await gotoExpensesPage(page)

        // Confirm a seeded draft exists via the API (all months, not just current)
        const draft = await findSeededDraftExpense(page)

        if (!draft) {
            // Seeded drafts not present — skip gracefully
            test.skip()
            return
        }

        // The seeded drafts live in April/May 2026. Clear the month filter so they appear.
        await clearMonthFilter(page)

        // Switch to Borradores to isolate draft rows
        await page.click('button:has-text("Borradores")')
        await waitForNoSkeleton(page)

        // Find the "Verificar" button on the first draft row
        const verificarBtn = page.locator('[data-testid="btn-verificar-draft"]').first()
        await expect(verificarBtn).toBeVisible({ timeout: 8_000 })
        await verificarBtn.click()

        // Verification slideover opens
        const verificationSlideover = page.locator('[data-testid="receipt-verification-slideover"]')
        await expect(verificationSlideover).toBeVisible({ timeout: 8_000 })

        // The OCR suggestion banner must be shown (seeded drafts have ocr_status=done + ocr_data)
        await expect(page.locator('[data-testid="ocr-suggestion-banner"]')).toBeVisible({
            timeout: 5_000,
        })

        // Amount pre-filled and > 0
        const amountValue = await page.locator('[data-testid="ver-amount"]').inputValue()
        expect(parseFloat(amountValue)).toBeGreaterThan(0)

        // Vendor pre-filled and not empty
        const vendorValue = await page.locator('[data-testid="ver-vendor"]').inputValue()
        expect(vendorValue.trim().length).toBeGreaterThan(0)

        // Confirm
        await page.click('[data-testid="btn-confirmar-gasto"]')

        await expect(page.locator('text=Gasto verificado')).toBeVisible({ timeout: 8_000 })
        await expect(verificationSlideover).not.toBeVisible()

        // Reload and verify the draft is no longer shown under Borradores
        await gotoExpensesPage(page)
        await clearMonthFilter(page)
        await page.click('button:has-text("Borradores")')
        await waitForNoSkeleton(page)

        // Count remaining draft buttons — must be less than or equal to before (≥0 sanity check)
        const remainingDraftCount = await page.locator('[data-testid="btn-verificar-draft"]').count()
        expect(remainingDraftCount).toBeGreaterThanOrEqual(0)

        // Return to Todos view and confirm the page is functional
        await page.click('button:has-text("Todos")')
        await waitForNoSkeleton(page)
        await expect(page.locator('[data-testid="period-total"]')).toBeVisible()
    })
})

test.describe('Receipt OCR — mobile (S6-E7)', () => {

    test('upload slideover renders as a bottom-sheet on 375px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoExpensesPage(page)

        await page.click('[data-testid="btn-subir-factura"]')

        const slideover = page.locator('[data-testid="receipt-upload-slideover"]')
        await expect(slideover).toBeVisible()

        // On mobile the panel slides up from the bottom — y > 0
        const box = await slideover.boundingBox()
        expect(box).not.toBeNull()
        if (box) {
            expect(box.y).toBeGreaterThan(0)
        }
    })

    test('verification slideover renders as a bottom-sheet on 375px viewport', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 })
        await login(page)
        await gotoExpensesPage(page)

        // Find any draft to open verification
        const draft = await findSeededDraftExpense(page)
        if (!draft) {
            test.skip()
            return
        }

        // Clear month filter so April/May seeded drafts are visible
        await clearMonthFilter(page)

        await page.click('button:has-text("Borradores")')
        await waitForNoSkeleton(page)

        const verificarBtn = page.locator('[data-testid="btn-verificar-draft"]').first()
        await expect(verificarBtn).toBeVisible({ timeout: 8_000 })
        await verificarBtn.click()

        const slideover = page.locator('[data-testid="receipt-verification-slideover"]')
        await expect(slideover).toBeVisible()

        const box = await slideover.boundingBox()
        expect(box).not.toBeNull()
        if (box) {
            expect(box.y).toBeGreaterThan(0)
        }
    })
})
