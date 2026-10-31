import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { SpinMenuButton } from '../SpinWheel';
import { usePage } from '@inertiajs/react';
import { router } from '@inertiajs/react';
import Icon, { SOCIAL_ICONS } from '../Icons';
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
            className={`lg:hidden fixed inset-0 z-[100] ${open ? '' : 'pointer-events-none'}`}
            inert={!open}
            aria-hidden={!open}
        >
            {/* Dim overlay */}
            <div
                className={`absolute inset-0 bg-ink-900/35 backdrop-blur-[3px] transition-opacity duration-500 ${open ? 'opacity-100' : 'opacity-0'}`}
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
                className={`absolute top-0 left-0 h-[100dvh] w-[88%] max-w-[380px] bg-white rounded-r-[28px] flex flex-col shadow-[30px_0_80px_-30px_rgb(0_0_0/0.45)] outline-none will-change-transform ${open ? 'translate-x-0' : '-translate-x-full'}`}
                style={{ transition: 'transform .5s cubic-bezier(.22,.61,.36,1)' }}
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
                <div className="flex items-center justify-between h-16 px-5 shrink-0">
                    <SmartLink href={urls.home || '/'} onClick={close} className="shrink-0">
                        {(logo.mobile || logo.desktop) ? (
                            <img src={logo.mobile || logo.desktop} alt={storeName} style={{ height: `${logo.hMobile || 32}px` }} className="w-auto" />
                        ) : (
                            <span className="font-display text-2xl tracking-[-0.02em] text-ink-900">{storeName}</span>
                        )}
                    </SmartLink>
                    <button onClick={close} aria-label="Close menu" className="grid place-items-center w-10 h-10 -mr-1 rounded-full bg-mist-100 text-ink-900">
                        <Icon name="close" className="w-4 h-4" strokeWidth={1.8} />
                    </button>
                </div>

                {/* Search */}
                <div className="px-5 pb-3 shrink-0">
                    <form action={urls.shop || '/shop'} method="GET" role="search" className="relative">
                        <input
                            name="q"
                            aria-label="Search jewelry"
                            placeholder="Search jewelry…"
                            autoComplete="off"
                            className="w-full rounded-full border-0 bg-mist-100 pl-11 pr-4 h-12 text-[15px] placeholder:text-ink-700/50 focus:outline-none focus:ring-2 focus:ring-ink-900/15"
                        />
                        <Icon name="search" className="w-[18px] h-[18px] absolute left-4 top-1/2 -translate-y-1/2 text-ink-700/50" strokeWidth={1.75} />
                    </form>
                </div>

                {/* Navigation */}
                <nav aria-label="Main" className="flex-1 overflow-y-auto overscroll-contain px-5 py-2">
                    <SmartLink href={urls.home || '/'} onClick={close} className="flex items-center py-3.5 border-b border-ink-900/[0.07] font-display text-[1.6rem] leading-tight tracking-[-0.015em] text-ink-900">Home</SmartLink>
                    {(menu.items || []).map((item, i) => (
                        <MobileItem key={i} item={item} onNavigate={close} />
                    ))}
                    <SpinMenuButton onClick={close} className="mt-4 flex w-full items-center gap-3 rounded-2xl bg-ink-900 px-4 py-3.5 text-left font-display text-[1.25rem] text-white" />
                    {menu.cta && (
                        <SmartLink href={menu.cta.url} onClick={close} className="btn-primary w-full mt-5">{menu.cta.label}</SmartLink>
                    )}

                    <div className="mt-6 rounded-[22px] bg-mist-50 p-2">
                        <p className="px-3 pt-2 pb-1 eyebrow">Your account</p>
                        {acctLinks.map(([href, label, icon]) => (
                            <a key={label} href={href} onClick={close} className="flex items-center gap-3 px-3 py-2.5 rounded-xl hover:bg-white text-[14px] text-ink-900">
                                <Icon name={icon} className="w-5 h-5 text-ink-700/70" strokeWidth={1.5} />
                                {label}
                            </a>
                        ))}
                    </div>
                </nav>

                {/* Footer with social icons */}
                <div className="shrink-0 border-t border-ink-900/[0.07] px-5 py-3 flex items-center justify-between" style={{ paddingBottom: 'calc(0.75rem + env(safe-area-inset-bottom))' }}>
                    <span className="text-xs text-ink-700/70">{storeName}</span>
                    <div className="flex items-center gap-2">
                        {(footer.socials || []).map(({ platform, label, url }) => {
                            const Glyph = SOCIAL_ICONS[platform];

                            return Glyph && (
                                <a key={platform} href={url} target="_blank" rel="noopener" aria-label={label} className="w-10 h-10 grid place-items-center rounded-full bg-mist-100 text-ink-900">
                                    <Glyph className="w-4.5 h-4.5" />
                                </a>
                            );
                        })}
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
            <SmartLink href={item.url} {...(item.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="flex items-center gap-2 py-3.5 border-b border-ink-900/[0.07] font-display text-[1.6rem] leading-tight tracking-[-0.015em] text-ink-900">
                {item.label}{badge}
            </SmartLink>
        );
    }

    return (
        <div className="border-b border-ink-900/[0.07]">
            {/* Tapping a parent expands its submenu; the category page itself is
                reached via the "View all …" link at the bottom of the panel. */}
            <button type="button" onClick={() => setSub(!sub)} aria-expanded={sub} aria-label={`Toggle ${item.label}`} className="w-full flex items-center justify-between text-left">
                <span className="flex items-center gap-2 py-3.5 font-display text-[1.6rem] leading-tight tracking-[-0.015em] text-ink-900">{item.label}{badge}</span>
                <span className={`grid place-items-center w-9 h-9 rounded-full transition-colors duration-300 ${sub ? 'bg-ink-900 text-white' : 'bg-mist-100 text-ink-900'}`}>
                    <Icon name="chevronDown" className={`w-3.5 h-3.5 transition-transform duration-300 ${sub ? 'rotate-180' : ''}`} strokeWidth={2} />
                </span>
            </button>
            <div className={`grid transition-[grid-template-rows] duration-300 ${sub ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'}`}>
                <div className="overflow-hidden">
                    <div className="pb-3 pl-1 space-y-0.5">
                        {type === 'mega' ? (item.columns || []).map((col, i) => (
                            <div key={i} className="contents">
                                {col.heading && <p className="pt-2 eyebrow">{col.heading}</p>}
                                {col.links.map((l, j) => (
                                    <SmartLink key={j} href={l.url} {...(l.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="block py-2 text-[15px] text-ink-700">{l.label}</SmartLink>
                                ))}
                            </div>
                        )) : (item.children || []).map((child, i) => (
                            <SmartLink key={i} href={child.url} {...(child.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="block py-2 text-[15px] text-ink-700">{child.label}</SmartLink>
                        ))}
                        {item.url && (
                            <SmartLink href={item.url} {...(item.new_tab ? { target: '_blank', rel: 'noopener' } : {})} onClick={onNavigate} className="block py-2 text-[13px] font-medium tracking-[0.06em] uppercase text-ink-900">View all {item.label} →</SmartLink>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
