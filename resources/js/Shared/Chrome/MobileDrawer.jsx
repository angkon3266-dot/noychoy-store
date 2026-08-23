import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import Icon, { Facebook, Instagram } from '../Icons';
import SmartLink from '../SmartLink';
import { trapTab } from '../focusTrap';

// Off-canvas mobile nav. Open state lives in a module-level store shared with
// the Header via context — the React equivalent of Alpine's $store.mobileNav.
const MobileNavContext = createContext({ open: false, toggle: () => {}, close: () => {} });

export function MobileNavProvider({ children }) {
    const [open, setOpen] = useState(false);

    const close = useCallback(() => {
        setOpen(false);
        document.body.style.overflow = '';
        document.body.style.paddingRight = '';
    }, []);

    const show = useCallback(() => {
        setOpen(true);
        // Body scroll-lock, compensating the scrollbar so nothing shifts.
        const sw = window.innerWidth - document.documentElement.clientWidth;
        document.body.style.overflow = 'hidden';
        if (sw > 0) document.body.style.paddingRight = sw + 'px';
    }, []);

    const toggle = useCallback(() => (open ? close() : show()), [open, close, show]);

    // Close on navigation.
    useEffect(() => router.on('navigate', () => close()), [close]);

    return (
        <MobileNavContext.Provider value={{ open, toggle, close }}>
            {children}
        </MobileNavContext.Provider>
    );
}

export function useMobileNav() {
    return useContext(MobileNavContext);
}

export default function MobileDrawer() {
    const { props } = usePage();
    const chrome = props.chrome || {};
    const { logo = {}, menu = {}, urls = {}, footer = {}, storeName } = chrome;
    const { open, close } = useMobileNav();
    const panelRef = useRef(null);
    const touchX = useRef(null);
    const restoreRef = useRef(null);

    useEffect(() => {
        if (open) {
            // Remember what opened us, so Escape puts the keyboard back where
            // it was rather than at the top of the document.
            restoreRef.current = document.activeElement;
            panelRef.current?.focus();
        } else if (restoreRef.current) {
            restoreRef.current.focus?.();
            restoreRef.current = null;
        }
    }, [open]);

    useEffect(() => {
        // Only listen while we are actually open — otherwise this drawer
        // swallowed Escape from the mini-cart and the product lightbox.
        if (!open) return;
        const esc = (e) => e.key === 'Escape' && close();
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [open, close]);

    const acctLinks = [
        [props.customer ? urls.account : urls.login, props.customer ? 'My account' : 'Sign in / Register', 'userFull'],
        [urls.accountLoved, 'Wishlist', 'heart'],
        [urls.accountOrders, 'My orders', 'bag'],
        [urls.track, 'Track order', 'trackBox'],
        [urls.contact, 'Contact us', 'mail'],
    ];

    return (
        // `inert` takes the whole closed drawer out of the tab order, the
        // pointer and the accessibility tree in one attribute — previously a
        // keyboard user tabbed straight into an off-screen menu. aria-hidden is
        // the fallback for engines without inert. role="dialog" moved onto the
        // panel below: on this element it claimed a modal was open on every
        // page of the site.
        <div
            className={`md:hidden fixed inset-0 z-[100] ${open ? '' : 'pointer-events-none'}`}
            inert={!open}
            aria-hidden={!open}
        >
            {/* Dim overlay */}
            <div
                className={`absolute inset-0 transition-opacity duration-300 ${open ? 'opacity-100' : 'opacity-0'}`}
                style={{ background: 'rgba(0,0,0,.45)' }}
                onClick={close}
            />

            {/* Panel */}
            <aside
                ref={panelRef}
                tabIndex={-1}
                role="dialog"
                aria-modal="true"
                aria-label="Menu"
                onKeyDown={(e) => trapTab(e, panelRef.current)}
                className={`absolute top-0 left-0 h-[100dvh] w-[85%] max-w-[360px] bg-white flex flex-col shadow-2xl outline-none will-change-transform ${open ? 'translate-x-0' : '-translate-x-full'}`}
                style={{ transition: 'transform .3s cubic-bezier(.22,.61,.36,1)' }}
                onTouchStart={(e) => { touchX.current = e.changedTouches[0].clientX; }}
                onTouchMove={(e) => {
                    if (touchX.current !== null && e.changedTouches[0].clientX - touchX.current < -60) {
                        close();
                        touchX.current = null;
                    }
                }}
                onTouchEnd={() => { touchX.current = null; }}
            >
                {/* Logo + close */}
                <div className="flex items-center justify-between h-16 px-4 border-b border-gold-100 shrink-0">
                    <SmartLink href={urls.home || '/'} onClick={close} className="shrink-0">
                        {(logo.mobile || logo.desktop) ? (
                            <img src={logo.mobile || logo.desktop} alt={storeName} style={{ height: `${logo.hMobile || 32}px` }} className="w-auto" />
                        ) : (
                            <span className="font-display text-xl font-bold text-gold-700">{storeName}</span>
                        )}
                    </SmartLink>
                    <button onClick={close} aria-label="Close menu" className="p-2 -mr-2 text-ink-700/70 hover:text-ink-900">
                        <Icon name="close" className="w-6 h-6" strokeWidth={1.75} />
                    </button>
                </div>

                {/* Search */}
                <div className="px-4 py-3 border-b border-gold-50 shrink-0">
                    <form action={urls.shop || '/shop'} method="GET" role="search" className="relative">
                        <input
                            name="q"
                            aria-label="Search jewelry"
                            placeholder="Search jewelry…"
                            autoComplete="off"
                            className="w-full rounded-full border border-ink-100 bg-ink-50/60 pl-10 pr-4 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-gold-400/50"
                        />
                        <Icon name="search" className="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-ink-700/40" strokeWidth={1.75} />
                    </form>
                </div>

                {/* Navigation */}
                <nav aria-label="Main" className="flex-1 overflow-y-auto overscroll-contain px-2 py-2">
                    <SmartLink href={urls.home || '/'} onClick={close} className="block px-2 py-3 rounded-lg hover:bg-gold-50 border-b border-gold-50">Home</SmartLink>
                    {(menu.items || []).map((item, i) => (
                        <MobileItem key={i} item={item} onNavigate={close} />
                    ))}
                    {menu.cta && (
                        <SmartLink href={menu.cta.url} onClick={close} className="block mt-2 rounded-full bg-gold-600 text-white text-center px-4 py-2.5 font-medium">{menu.cta.label}</SmartLink>
                    )}

                    <div className="mt-3 pt-3 border-t border-ink-100">
                        <p className="px-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-ink-700/70">Your account</p>
                        {acctLinks.map(([href, label, icon]) => (
                            <a key={label} href={href} onClick={close} className="flex items-center gap-3 px-2 py-2.5 rounded-lg hover:bg-gold-50 text-sm">
                                <Icon name={icon} className="w-5 h-5 text-ink-700/60" strokeWidth={1.6} />
                                {label}
                            </a>
                        ))}
                    </div>
                </nav>

                {/* Footer with social icons */}
                <div className="shrink-0 border-t border-ink-100 px-4 py-3 flex items-center justify-between" style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}>
                    <span className="text-xs text-ink-700/70">{storeName}</span>
                    <div className="flex items-center gap-2">
                        {footer.facebook && (
                            <a href={footer.facebook} target="_blank" rel="noopener" aria-label="Facebook" className="w-9 h-9 grid place-items-center rounded-full bg-ink-50 text-ink-700/70 hover:text-gold-700">
                                <Facebook className="w-4.5 h-4.5" />
                            </a>
                        )}
                        {footer.instagram && (
                            <a href={footer.instagram} target="_blank" rel="noopener" aria-label="Instagram" className="w-9 h-9 grid place-items-center rounded-full bg-ink-50 text-ink-700/70 hover:text-gold-700">
                                <Instagram className="w-4.5 h-4.5" />
                            </a>
                        )}
                    </div>
                </div>
            </aside>
        </div>
    );
}

function MobileItem({ item, onNavigate }) {
    const [sub, setSub] = useState(false);
    const type = item.type || (item.columns?.length ? 'mega' : (item.children?.length ? 'dropdown' : 'link'));
    const badge = item.badge ? <span className="badge bg-gold-600 text-white text-[9px]">{item.badge}</span> : null;

    if (type === 'link') {
        return (
            <SmartLink href={item.url} {...(item.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="flex items-center gap-2 px-2 py-3 rounded-lg hover:bg-gold-50 border-b border-gold-50">
                {item.label}{badge}
            </SmartLink>
        );
    }

    return (
        <div className="border-b border-gold-50">
            {/* Tapping a parent expands its submenu; the category page itself is
                reached via the "View all …" link at the bottom of the panel. */}
            <button type="button" onClick={() => setSub(!sub)} aria-expanded={sub} aria-label={`Toggle ${item.label}`} className="w-full flex items-center justify-between text-left">
                <span className="flex items-center gap-2 px-2 py-3">{item.label}{badge}</span>
                <span className="p-2">
                    <Icon name="chevronRight" className={`w-4 h-4 transition-transform duration-200 ${sub ? 'rotate-90' : ''}`} strokeWidth={2} />
                </span>
            </button>
            <div className={`grid transition-[grid-template-rows] duration-300 ${sub ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}>
                <div className="overflow-hidden">
                    <div className="pb-2 pl-4 space-y-0.5">
                        {type === 'mega' ? (item.columns || []).map((col, i) => (
                            <div key={i} className="contents">
                                {col.heading && <p className="pt-1.5 text-xs font-bold text-gold-700 uppercase tracking-wide">{col.heading}</p>}
                                {col.links.map((l, j) => (
                                    <SmartLink key={j} href={l.url} {...(l.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="block py-2 text-sm text-ink-700/80">{l.label}</SmartLink>
                                ))}
                            </div>
                        )) : (item.children || []).map((child, i) => (
                            <SmartLink key={i} href={child.url} {...(child.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="block py-2 text-sm text-ink-700/80">{child.label}</SmartLink>
                        ))}
                        {item.url && (
                            <SmartLink href={item.url} {...(item.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="block py-2 text-sm font-medium text-gold-700">View all {item.label} →</SmartLink>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
