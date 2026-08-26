import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { CartProvider, useCart } from '../CartContext';
import AnnouncementBar from './AnnouncementBar';
import Header from './Header';
import MobileDrawer, { MobileNavProvider } from './MobileDrawer';
import MemberBar from './MemberBar';
import MiniCart from './MiniCart';
import Footer from './Footer';
import FloatingStack from './FloatingStack';
import BottomNav from './BottomNav';
import PushPrompt from './PushPrompt';
import { initReveal } from '../reveal';

// Persistent layout: assigned via `Page.layout = (page) => <Layout>{page}</Layout>`
// so header/drawer/mini-cart state and scroll behaviour survive navigations.
export default function Layout({ children }) {
    return (
        <CartProvider>
            <MobileNavProvider>
                <Chrome>{children}</Chrome>
            </MobileNavProvider>
        </CartProvider>
    );
}

function Chrome({ children }) {
    const { props, url } = usePage();
    const { toast } = useCart();

    // Premium scroll-reveal, re-armed on every page navigation.
    useEffect(() => {
        const raf = requestAnimationFrame(() => initReveal());
        return () => cancelAnimationFrame(raf);
    }, [url]);

    // The Blade head renders the title on first load; SPA visits must keep it
    // in sync themselves.
    //
    // `seoTitle` is what the server put in <title> — the search-facing string,
    // which for a product is "Hoop Earrings — Price in Bangladesh" rather than
    // the bare heading. Preferring it here matters twice over: it keeps SPA
    // titles identical to server ones, and it stops this effect from quietly
    // overwriting the server's title with the weaker one on first mount, where
    // a rendering crawler would have read the replacement.
    useEffect(() => {
        const store = props.chrome?.storeName;
        const title = props.seoTitle || props.pageTitle;
        if (!title || !store) return;

        document.title = title.toLowerCase().includes(store.toLowerCase())
            ? title
            : `${title} | ${store}`;
    }, [props.seoTitle, props.pageTitle, props.chrome?.storeName]);

    // A normal page load moves focus to the top of the document and a screen
    // reader announces the new title. An SPA navigation does neither unless we
    // do it: without this, keyboard focus stays on the link that was clicked
    // (which no longer exists) and nothing is announced.
    const mainRef = useRef(null);
    const [announcement, setAnnouncement] = useState('');
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        mainRef.current?.focus({ preventScroll: true });
        setAnnouncement(`${props.pageTitle || 'Page'} loaded`);
    }, [url]);

    // Anything mid-flight should say so, for anyone who cannot see the
    // progress bar.
    useEffect(() => router.on('start', () => setAnnouncement('Loading…')), []);

    return (
        <div className="min-h-screen flex flex-col bg-gold-50 text-ink-800">
            {/* First tab stop on every page: keyboard users should not have to
                walk the whole menu to reach the products. */}
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:z-[200] focus:top-3 focus:left-3 focus:rounded-lg focus:bg-ink-900 focus:px-4 focus:py-2.5 focus:text-sm focus:text-white focus:shadow-lg"
            >
                Skip to content
            </a>

            {/* Route changes announced to screen readers. */}
            <div aria-live="polite" aria-atomic="true" className="sr-only">{announcement}</div>

            <AnnouncementBar config={props.chrome?.announcement} />
            <Header />
            <MobileDrawer />
            <MemberBar config={props.chrome?.memberBar} />
            <MiniCart />
            <FlashBanners flash={props.flash} />
            <PushPrompt />

            <main id="main" ref={mainRef} tabIndex={-1} className="flex-1 outline-none">{children}</main>

            <Footer />
            <FloatingStack />
            <BottomNav />

            {/* Toast */}
            <div
                aria-live="polite"
                className={`fixed bottom-20 md:bottom-6 left-1/2 -translate-x-1/2 z-[70] rounded-full bg-ink-900 text-white px-5 py-2.5 text-sm shadow-lg transition-all duration-300 ${toast ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2 pointer-events-none'}`}
            >
                {toast}
            </div>
        </div>
    );
}

function FlashBanners({ flash }) {
    const seen = useRef(null);
    if (!flash?.success && !flash?.error) return null;

    return (
        <div className="mx-auto max-w-7xl px-4 mt-4 w-full" ref={seen}>
            {flash.success && (
                <div className="rounded-md bg-success-50 border border-success-200 text-success-800 px-4 py-3 text-sm">{flash.success}</div>
            )}
            {flash.error && (
                <div className="rounded-md bg-danger-50 border border-danger-200 text-danger-800 px-4 py-3 text-sm">{flash.error}</div>
            )}
        </div>
    );
}
