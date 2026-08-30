import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
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
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
    build: {
        rollupOptions: {
            output: {
                // Keep framework code in a content-hashed, long-lived chunk.
                // The mobile app and dashboard change much more often than
                // Vue/Inertia/Axios.  This lets returning visitors reuse the
                // framework bytes from the browser/PWA cache after a release
                // instead of re-downloading them with the application entry.
                manualChunks(id) {
                    const normalizedId = id.replaceAll('\\', '/');

                    if (normalizedId.includes('/node_modules/')) {
                        return 'vendor';
                    }
                },
            },
        },
    },
});
