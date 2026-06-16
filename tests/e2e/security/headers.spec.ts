import { test, expect } from '@playwright/test';

/**
 * Security headers, exercised against the real served bundle.
 *
 * Two things must hold at once: the defensive headers ship on the document
 * response, AND the Content-Security-Policy does not break the SPA — no script,
 * style, font or XHR is refused. We assert the latter by failing on any console
 * message that mentions a CSP violation while the app boots and mounts.
 */

const HYDRATION_TIMEOUT = 15_000;

test('SPA ships security headers and the CSP does not block the app', async ({ page }) => {
    const cspViolations: string[] = [];
    page.on('console', (msg) => {
        const text = msg.text();
        if (/content security policy|refused to (load|execute|connect|apply)/i.test(text)) {
            cspViolations.push(text);
        }
    });

    const response = await page.goto('/login');
    expect(response, 'navigation returned a response').not.toBeNull();

    const headers = response!.headers();
    expect(headers['x-content-type-options']).toBe('nosniff');
    expect(headers['x-frame-options']).toBe('DENY');
    expect(headers['referrer-policy']).toBe('strict-origin-when-cross-origin');
    expect(headers['permissions-policy']).toContain('camera=()');
    expect(headers['content-security-policy']).toContain("frame-ancestors 'none'");

    // App must actually mount — proves the policy let the bundle's scripts run.
    await page.waitForFunction(
        () => {
            const app = document.querySelector('#app');
            return app !== null && app.children.length > 0;
        },
        { timeout: HYDRATION_TIMEOUT },
    );
    await expect(page.locator('input#email')).toBeVisible();

    expect(cspViolations, `CSP blocked resources:\n${cspViolations.join('\n')}`).toEqual([]);
});
