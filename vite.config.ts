import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: { base: null, includeAbsolute: false },
            },
        }),
        tailwindcss(),
        VitePWA({
            // O Laravel compila para public/build, mas o service worker e o manifest têm de estar na raiz
            // (public/) para controlarem todo o site (scope "/").
            outDir: 'public',
            base: '/',
            scope: '/',
            buildBase: '/',
            filename: 'sw.js',
            registerType: 'prompt',
            injectRegister: false,
            // O manifest é estático (public/manifest.webmanifest) para ficar na raiz do site
            manifest: false,
            workbox: {
                // Tratamento de Web Push (eventos push e notificationclick)
                importScripts: ['/sw-push.js'],
                globDirectory: 'public',
                globPatterns: ['build/assets/*.{js,css,woff2}', 'icons/*.png', 'offline.html', 'favicon.ico', 'manifest.webmanifest'],
                // A fonte só é descarregada para os caracteres usados (unicode-range); não pré-carregar alfabetos que não usamos
                globIgnores: ['build/assets/inter-{cyrillic,cyrillic-ext,greek,greek-ext,vietnamese}-*.woff2'],
                dontCacheBustURLsMatching: /^build\/assets\//,
                cleanupOutdatedCaches: true,
                // As páginas são geradas pelo servidor (Inertia): nunca servir HTML em cache.
                // Sem rede, as navegações mostram a página de aviso offline.
                navigateFallback: null,
                runtimeCaching: [
                    {
                        urlPattern: ({ request }) => request.mode === 'navigate',
                        handler: 'NetworkOnly',
                        options: {
                            precacheFallback: { fallbackURL: '/offline.html' },
                        },
                    },
                ],
            },
            devOptions: {
                enabled: false,
            },
        }),
    ],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
