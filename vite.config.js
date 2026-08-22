import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [
        laravel({
            // app.js = Alpine (Blade pages + admin) · inertia.jsx = React storefront
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/inertia.jsx'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                bunny('Playfair Display', {
                    weights: [500, 600, 700],
                }),
            ],
        }),
        tailwindcss(),
        react(),
    ],
    build: {
        rollupOptions: {
            output: {
                // React + Inertia in their own chunk: it keeps its hash across
                // deploys, so returning visitors re-use the cached copy instead
                // of re-downloading the framework after every content change.
                // (Rolldown — Vite 8's bundler — only accepts the function form.)
                manualChunks: (id) =>
                    /node_modules[\/](react|react-dom|scheduler|@inertiajs)[\/]/.test(id)
                        ? 'vendor'
                        : undefined,
            },
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
