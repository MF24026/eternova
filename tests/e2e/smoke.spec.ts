import { test, expect } from '@playwright/test';

/**
 * Smoke tests para verificar que las rutas criticas del SaaS responden
 * y renderizan los elementos clave del design system.
 *
 * Sprint 0.5 baseline: solo HTTP 200 + elementos minimos visibles.
 * Sprint 1+ agregara flujos completos (login real, checkout, etc).
 */

const HYDRATION_TIMEOUT = 15_000;

async function gotoAndWaitForApp(page, path: string) {
    await page.goto(path);
    // Wait for Inertia/Vue to mount the app component into #app
    await page.waitForFunction(
        () => {
            const app = document.querySelector('#app');
            return app !== null && app.children.length > 0;
        },
        { timeout: HYDRATION_TIMEOUT },
    );
}

test.describe('Storefront publico', () => {
    test('Home carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('Catalogo carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/catalog');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('ProductDetail carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/product/p1');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('Checkout carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/checkout');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('OrderTracking carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/track');
        await expect(page.locator('#app')).not.toBeEmpty();
    });
});

test.describe('Auth', () => {
    test('Login muestra form con campos correo/contrasena y boton Entrar', async ({ page }) => {
        await gotoAndWaitForApp(page, '/login');
        await expect(page.locator('input#email')).toBeVisible();
        await expect(page.locator('input#password')).toBeVisible();
        await expect(page.getByRole('button', { name: /entrar/i })).toBeVisible();
    });

    test('Signup (onboarding wizard) muestra el paso de cuenta', async ({ page }) => {
        await gotoAndWaitForApp(page, '/signup');
        await expect(page.locator('input#account-name')).toBeVisible();
        await expect(page.locator('input#account-email')).toBeVisible();
        await expect(page.locator('input#account-password')).toBeVisible();
    });
});

test.describe('Admin (placeholders, sin auth real)', () => {
    test('Dashboard carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/admin/dashboard');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('POS carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/admin/pos');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('Orders carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/admin/orders');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('Inventory carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/admin/inventory');
        await expect(page.locator('#app')).not.toBeEmpty();
    });

    test('Settings carga', async ({ page }) => {
        await gotoAndWaitForApp(page, '/admin/settings');
        await expect(page.locator('#app')).not.toBeEmpty();
    });
});
