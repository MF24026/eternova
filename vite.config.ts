import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';

// NOTE: The laravel-vite-plugin is intentionally NOT used here.
// Inertia is dormant (issue #12 owns the full page migration from Inertia to Vue Router).
// The SPA uses a single entry point + Vue Router lazy loading.
// Multi-entry (admin.ts / storefront.ts / super-admin.ts) is a deferred optimization:
//   it requires code-splitting per tenant-app-area and belongs to the frontend bootstrap
//   sprint (issue #11, S0-E7). For now, single entry keeps the build simple and correct.

const vitePort = parseInt(process.env.VITE_PORT ?? '5174', 10);

export default defineConfig({
    plugins: [
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        tailwindcss(),
    ],

    resolve: {
        alias: {
            '@': resolve(__dirname, 'resources/js'),
        },
    },

    // Single entry point for the Vue 3 SPA.
    // TODO(#11): When S0-E7 (frontend bootstrap) lands, evaluate splitting into
    //   admin / storefront / super-admin entries to reduce initial bundle size.
    build: {
        rollupOptions: {
            input: {
                app: resolve(__dirname, 'resources/js/app.js'),
            },
        },
        manifest: true,
        outDir: 'public/build',
    },

    server: {
        host: '0.0.0.0',
        port: vitePort,
        strictPort: true,
        hmr: {
            host: 'localhost',
            port: vitePort,
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
