// React storefront entry (Inertia). Blade pages keep using app.js (Alpine);
// this bundle only loads on routes whose controllers return Inertia::render().
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

// Meta Pixel PageView for SPA navigations. The pixel snippet in <head> fires
// the initial PageView on script init, so the very first `navigate` event
// (the initial page) must not fire a second one.
//
// Each page carries the id its server PageView went out under (metaPageView,
// from SendMetaPageView), and the Pixel uses it so Meta merges the two. A
// partial reload (a filter, a refreshed total) keeps the page's id and is not
// a new page, so it fires nothing; a page with no id (the server copy off)
// keeps the old behaviour.
let firstNavigate = true;
let lastPageView = null;
router.on('navigate', (event) => {
    const props = event.detail?.page?.props || {};

    // Keep the CSRF <meta> fresh — fetch() helpers read it on every call.
    const token = props.csrf;
    const meta = document.querySelector('meta[name="csrf-token"]');
    if (token && meta && meta.content !== token) meta.content = token;

    const pageViewId = props.metaPageView || null;
    if (firstNavigate) {
        firstNavigate = false;
        lastPageView = pageViewId;
        return;
    }
    if (pageViewId && pageViewId === lastPageView) return;
    lastPageView = pageViewId;
    if (window.track) window.track('PageView', {}, pageViewId ? { eventID: pageViewId } : undefined);
});

createInertiaApp({
    // Lazy, so Vite emits one chunk per page: a first-time visitor downloads
    // the shell plus the page they asked for, not all 23 pages. The shared
    // chrome (Layout, ProductCard…) stays in the entry chunk.
    resolve: (name) => resolvePageComponent(`./Pages/${name}.jsx`, import.meta.glob('./Pages/**/*.jsx')),
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    // The page-load bar takes the store's own brand colour (set in Admin →
    // Appearance and written to :root by inertia.blade.php).
    progress: {
        color: getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#b6863a',
        delay: 150,
    },
});
