import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'path';

// laravel-vite-plugin IS required for the Laravel <-> Vite handshake:
//   - writes public/hot with the active dev server URL (blade @vite directive reads it)
//   - generates the asset manifest in the format @vite() expects at runtime
//   - refreshes blade views on file changes
// It is independent of Inertia. Inertia removal is owned by issue #12 (page migration).
// The SPA uses a single entry point + Vue Router lazy loading.
// Multi-entry (admin.ts / storefront.ts / super-admin.ts) is a deferred optimization
//   owned by issue #11 (S0-E7 frontend bootstrap).

const vitePort = parseInt(process.env.VITE_PORT ?? '5174', 10);

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/main.ts'],
            refresh: true,
        }),
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
