import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for Eternova end-to-end tests.
 *
 * Doctrina dual-layer (CLAUDE.md): toda mutacion del sistema requiere
 * PHPUnit feature test + Playwright E2E. Sin excepciones.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [
        ['list'],
        ['html', { open: 'never', outputFolder: 'tests/e2e-report' }],
    ],
    use: {
        // Inside Sail container the app is at localhost:80 (the external host
        // port 8080 is only the host-side mapping). Override with
        // PLAYWRIGHT_BASE_URL when running from outside the container.
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost',
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
        },
        {
            // Pixel 7 is a Chromium-engine device profile, so this project runs
            // on the same browser the desktop project already installs. iPhone
            // profiles pull the WebKit engine, which contradicts the project
            // name and is not provisioned in CI.
            name: 'chromium-mobile',
            use: { ...devices['Pixel 7'] },
        },
    ],
});
