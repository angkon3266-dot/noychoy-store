// React storefront entry (Inertia). Blade pages keep using app.js (Alpine);
// this bundle only loads on routes whose controllers return Inertia::render().
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

// Meta Pixel PageView for SPA navigations. The pixel snippet in <head> fires
// the initial PageView on script init, so the very first `navigate` event
// (the initial page) must not fire a second one.
let firstNavigate = true;
router.on('navigate', (event) => {
    // Keep the CSRF <meta> fresh — fetch() helpers read it on every call.
    const token = event.detail?.page?.props?.csrf;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (token && meta && meta.content !== token) meta.content = token;

    if (firstNavigate) {
        firstNavigate = false;
        return;
    }
    if (window.track) window.track('PageView');
});

createInertiaApp({
    // Lazy, so Vite emits one chunk per page: a first-time visitor downloads
    // the shell plus the page they asked for, not all 23 pages. The shared
    // chrome (Layout, ProductCard…) stays in the entry chunk.
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: '#b6863a',
        delay: 150,
    },
});
