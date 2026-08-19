import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { CartProvider, useCart } from '../CartContext';
import AnnouncementBar from './AnnouncementBar';
import Header from './Header';
import MobileDrawer, { MobileNavProvider } from './MobileDrawer';
import MemberBar from './MemberBar';
import MiniCart from './MiniCart';
import Footer from './Footer';
import FloatingStack from './FloatingStack';
import BottomNav from './BottomNav';
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
    // in sync themselves (same "X — Store" pattern as the server).
    useEffect(() => {
        const store = props.chrome?.storeName;
        if (props.pageTitle && store) document.title = `${props.pageTitle} — ${store}`;
    }, [props.pageTitle, props.chrome?.storeName]);

    return (
        <div className="min-h-screen flex flex-col bg-gold-50 text-ink-800">
            <AnnouncementBar config={props.chrome?.announcement} />
            <Header />
            <MobileDrawer />
            <MemberBar config={props.chrome?.memberBar} />
            <MiniCart />
            <FlashBanners flash={props.flash} />

            <main className="flex-1">{children}</main>

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
                <div className="rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">{flash.success}</div>
            )}
            {flash.error && (
                <div className="rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">{flash.error}</div>
            )}
        </div>
    );
}
