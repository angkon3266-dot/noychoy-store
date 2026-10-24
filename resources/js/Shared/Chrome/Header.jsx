import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import SmartLink from '../SmartLink';
import Icon from '../Icons';
import SearchBox from './SearchBox';
import NotificationsBell from './NotificationsBell';
import PinnedMessage from './PinnedMessage';
import { useCart } from '../CartContext';
import { useMobileNav } from './MobileDrawer';
import { t } from '../i18n';

// The storefront header (redesigned 2026-10-24).
//
// Glass bar that tightens once the page scrolls; the desktop menu opens full-
// width panels instead of small dropdowns; search is a full-width overlay with
// a large input rather than a cramped box in the bar. Every admin setting the
// old header read (logo sizes + alignment, menu icon, menu items/trigger, CTA,
// search toggle, the phone "saved" badge, the centre image) still drives it.
export default function Header() {
    const { props, url } = usePage();
    const chrome = props.chrome || {};
    const { logo = {}, menuIcon = {}, menu = {}, urls = {}, storeName } = chrome;
    const { count, savedText, openDrawer } = useCart();
    const nav = useMobileNav();
    const [searchOpen, setSearchOpen] = useState(false);
    const [imgOk, setImgOk] = useState(true);
    const [scrolled, setScrolled] = useState(false);
    const [openMenu, setOpenMenu] = useState(null);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 12);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    // Panels and search close on every navigation and on Escape.
    useEffect(() => router.on('navigate', () => { setOpenMenu(null); setSearchOpen(false); }), []);
    useEffect(() => {
        if (!searchOpen && openMenu === null) return undefined;
        const esc = (e) => { if (e.key === 'Escape') { setSearchOpen(false); setOpenMenu(null); } };
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [searchOpen, openMenu]);

    const logoAlignClass = logo.align === 'center'
        ? 'absolute left-1/2 -translate-x-1/2 lg:static lg:translate-x-0'
        : (logo.align === 'right' ? 'ml-auto lg:ml-0' : '');

    // No display utility in here: each use says grid / hidden sm:grid itself,
    // so two display classes never compete on one element.
    const iconBtn = 'relative place-items-center items-center justify-center w-10 h-10 rounded-full text-ink-900 hover:bg-ink-900/[0.06] transition-colors duration-300';
    const items = menu.items || [];
    const active = openMenu !== null ? items[openMenu] : null;

    return (
        <header className="sticky top-0 z-40" onMouseLeave={() => menu.trigger === 'hover' && setOpenMenu(null)}>
            {/* Inside the sticky header, so it floats with it (owner, 2026-09-19). */}
            <PinnedMessage config={chrome.pinned} />
            <div className={`relative transition-[background-color,box-shadow] duration-500 ${scrolled || active || searchOpen ? 'glass shadow-[0_1px_0_rgb(0_0_0/0.06),0_12px_30px_-24px_rgb(0_0_0/0.25)]' : 'bg-gold-50 shadow-[0_1px_0_rgb(0_0_0/0.05)]'}`}>
                <div className="mx-auto max-w-[1440px] px-4 lg:px-10">
                    <div className={`relative flex items-center gap-2 lg:gap-6 transition-[height] duration-500 ${scrolled ? 'h-14 lg:h-16' : 'h-16 lg:h-[76px]'}`}>
                        {/* Mobile menu toggle — custom icon with guaranteed SVG fallback */}
                        <button
                            onClick={nav.toggle}
                            className="lg:hidden -ml-2 grid place-items-center w-10 h-10 text-ink-900"
                            aria-label="Menu"
                            aria-expanded={nav.open}
                        >
                            {menuIcon.url && imgOk ? (
                                <img
                                    src={menuIcon.url}
                                    alt="Menu"
                                    width={menuIcon.height}
                                    height={menuIcon.height}
                                    decoding="async"
                                    className="menu-ico object-contain transition-transform duration-300"
                                    style={{ transform: `rotate(${nav.open ? menuIcon.rotation : 0}deg)` }}
                                    onError={() => setImgOk(false)}
                                />
                            ) : (
                                <svg aria-hidden="true" viewBox="0 0 24 24" className="w-6 h-6" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
                                    <path d="M3.5 8h17" /><path d="M3.5 16h11" />
                                </svg>
                            )}
                        </button>

                        {/* Logo */}
                        <SmartLink href={urls.home || '/'} className={`shrink-0 ${logoAlignClass}`} aria-label={`${storeName} — home`}>
                            {logo.desktop || logo.mobile ? (
                                <>
                                    {logo.desktop && <img src={logo.desktop} alt={storeName} height={logo.hDesktop} decoding="async" fetchPriority="high" className={`logo-d w-auto hidden lg:block transition-transform duration-500 origin-left ${scrolled ? 'scale-[0.86]' : ''}`} />}
                                    {logo.mobile && <img src={logo.mobile} alt={storeName} height={logo.hMobile} decoding="async" fetchPriority="high" className="logo-m w-auto lg:hidden" />}
                                </>
                            ) : (
                                <span className="font-display text-[1.6rem] lg:text-[1.85rem] leading-none tracking-[-0.02em] text-ink-900">
                                    {storeName}
                                </span>
                            )}
                        </SmartLink>

                        {/* Phones and tablets: the centre of the bar. The admin's optional
                            centre image when one is set; otherwise the animated wordmark
                            (owner, 2026-10-24), with the cart's saving tucked under it. */}
                        {!savedText && logo.center ? (
                            <a href={logo.centerLink} className="lg:hidden absolute left-1/2 -translate-x-1/2">
                                <img src={logo.center} alt="" height={logo.centerH} decoding="async" className="logo-center w-auto" />
                            </a>
                        ) : (
                            <div className="lg:hidden absolute left-1/2 -translate-x-1/2 flex flex-col items-center gap-1 pointer-events-none">
                                <SmartLink href={urls.home || '/'} tabIndex={-1} aria-hidden="true" className="pointer-events-auto">
                                    <Wordmark name={wordmarkName(urls.home, storeName)} compact={!!savedText} />
                                </SmartLink>
                                {savedText && (
                                    <SmartLink href={urls.cart || '/cart'} className="pointer-events-auto inline-flex items-center gap-1 rounded-full bg-ink-900 text-white text-[10px] font-semibold px-2 h-[18px] whitespace-nowrap" aria-label={`${savedText} saved — open cart`} lang={chrome.lang}>
                                        <Icon name="gift" className="w-2.5 h-2.5 text-gold-400" strokeWidth={2} />
                                        {chrome.lang === 'bn' ? `${savedText} সাশ্রয়` : `${savedText} saved`}
                                    </SmartLink>
                                )}
                            </div>
                        )}

                        {/* Desktop nav */}
                        <nav className="hidden lg:flex flex-1 items-center justify-center gap-2 text-[13px] font-medium tracking-[0.04em]" aria-label="Main">
                            {items.map((item, i) => (
                                <DesktopItem
                                    key={i}
                                    item={item}
                                    open={openMenu === i}
                                    current={isCurrent(item.url, url)}
                                    onOpen={() => setOpenMenu(i)}
                                    onCloseAll={() => setOpenMenu(null)}
                                    onToggle={() => setOpenMenu(openMenu === i ? null : i)}
                                    hover={menu.trigger === 'hover'}
                                />
                            ))}
                        </nav>

                        <div className="flex items-center gap-0.5 ml-auto lg:ml-0">
                            {menu.cta && (
                                <a href={menu.cta.url} className="hidden lg:inline-flex items-center gap-2 mr-2 rounded-full border border-ink-900/15 px-4 h-9 text-[12px] font-medium tracking-[0.06em] uppercase text-ink-900 hover:bg-ink-900 hover:text-white hover:border-ink-900 transition-colors duration-300">
                                    <span className="w-1.5 h-1.5 rounded-full bg-gold-500" aria-hidden="true" />
                                    {menu.cta.label}
                                </a>
                            )}
                            {menu.showSearch && (
                                <button type="button" onClick={() => { setSearchOpen(!searchOpen); setOpenMenu(null); }} className={`${iconBtn} grid`} aria-label="Search" aria-expanded={searchOpen}>
                                    <Icon name={searchOpen ? 'close' : 'search'} className="w-[21px] h-[21px]" strokeWidth={1.5} />
                                </button>
                            )}
                            {props.customer ? (
                                <>
                                    <NotificationsBell />
                                    <a href={urls.account} className={`${iconBtn} hidden sm:grid`} title="My account" aria-label="My account">
                                        <Icon name="user" className="w-[21px] h-[21px]" strokeWidth={1.5} />
                                    </a>
                                </>
                            ) : (
                                /* The bare icon said nothing about what joining is worth;
                                   the label puts the member price on every page. */
                                <a href={props.chrome?.membership?.pct ? urls.register : urls.login} className={`${iconBtn} hidden sm:inline-flex sm:w-auto sm:px-2.5 gap-1.5`} title={props.chrome?.membership?.pct ? `Join free — members save ${props.chrome.membership.pct}%` : 'Login'} aria-label="Sign in or join">
                                    <Icon name="user" className="w-[21px] h-[21px]" strokeWidth={1.5} />
                                    {props.chrome?.membership?.pct && (
                                        <span className="hidden lg:inline text-[12px] font-medium text-ink-900 whitespace-nowrap" lang={chrome.lang}>{t(chrome.lang, 'header.join', { pct: props.chrome.membership.pct })}</span>
                                    )}
                                </a>
                            )}
                            <button type="button" onClick={openDrawer} className={`${iconBtn} grid`} title="Cart" aria-label={count > 0 ? `Cart, ${count} item${count === 1 ? '' : 's'}` : 'Cart'}>
                                <Icon name="bag" className="w-[21px] h-[21px]" strokeWidth={1.5} />
                                {count > 0 && (
                                    <span aria-hidden="true" className="absolute top-0.5 right-0 min-w-[18px] h-[18px] px-1 rounded-full bg-ink-900 text-white text-[10px] font-semibold grid place-items-center ring-2 ring-white">{count}</span>
                                )}
                            </button>
                        </div>
                    </div>
                </div>

                {/* Desktop mega panel for the open menu item */}
                {active && <MegaPanel item={active} onClose={() => setOpenMenu(null)} />}

                {/* Search overlay */}
                {menu.showSearch && searchOpen && (
                    <div className="absolute inset-x-0 top-full pop-in">
                        <div className="bg-gold-50 border-t border-ink-900/5 shadow-[0_30px_60px_-30px_rgb(0_0_0/0.35)]">
                            <div className="mx-auto max-w-3xl px-4 lg:px-0 py-6 md:py-10">
                                <SearchBox lux autoFocus onNavigate={() => setSearchOpen(false)} />
                                <div className="mt-5 flex flex-wrap items-center gap-2">
                                    <span className="eyebrow mr-1">Popular</span>
                                    {items.slice(0, 5).map((item, i) => (
                                        <SmartLink key={i} href={item.url} onClick={() => setSearchOpen(false)} className="rounded-full border border-ink-900/10 bg-white px-3.5 py-1.5 text-[13px] text-ink-800 hover:border-ink-900 transition-colors">
                                            {item.label}
                                        </SmartLink>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </div>
            {(searchOpen || active) && (
                <button type="button" tabIndex={-1} aria-hidden="true" onClick={() => { setSearchOpen(false); setOpenMenu(null); }} onMouseEnter={() => menu.trigger === 'hover' && !searchOpen && setOpenMenu(null)} className="fixed inset-0 -z-10 bg-ink-900/20 backdrop-blur-[2px] cursor-default" />
            )}
        </header>
    );
}

/**
 * "Noychoy.com" — the site's own address, read from the home URL so it follows
 * the domain rather than being typed in. Falls back to the store name where
 * there is no real domain (localhost, an IP).
 */
function wordmarkName(home, storeName) {
    try {
        const host = new URL(home).hostname.replace(/^www\./, '');
        if (host.includes('.') && !/^[\d.]+$/.test(host)) return host.charAt(0).toUpperCase() + host.slice(1);
    } catch {
        // no usable URL — the store name below
    }
    return storeName;
}

// The wordmark: the brand's navy and orange drifting through the letters, a
// band of light passing over them every twelve seconds, and a small glint at
// the end of the name as the light leaves. All of it in app.css (.wordmark),
// and all of it still for reduced-motion visitors.
function Wordmark({ name, compact = false }) {
    const dot = name.indexOf('.');
    const base = dot > 0 ? name.slice(0, dot) : name;
    const tld = dot > 0 ? name.slice(dot) : '';

    return (
        <span className="relative inline-flex items-baseline whitespace-nowrap">
            <span className={`wordmark font-display leading-none ${compact ? 'text-[1.05rem]' : 'text-[1.3rem] sm:text-[1.45rem]'}`}>
                {base}
                {tld && <span className="italic text-[0.78em]">{tld}</span>}
            </span>
            <svg aria-hidden="true" viewBox="0 0 24 24" className="wordmark-glint absolute -top-1.5 -right-2.5 w-2.5 h-2.5 text-gold-500">
                <path fill="currentColor" d="M12 0c.6 5.6 6.4 11.4 12 12-5.6.6-11.4 6.4-12 12-.6-5.6-6.4-11.4-12-12C5.6 11.4 11.4 5.6 12 0z" />
            </svg>
        </span>
    );
}

function isCurrent(href, url) {
    if (!href) return false;
    try {
        const path = new URL(href, window.location.origin).pathname;
        return path !== '/' && url.split('?')[0] === path;
    } catch {
        return false;
    }
}

function DesktopItem({ item, open, current, onOpen, onCloseAll, onToggle, hover }) {
    const type = item.type || (item.columns?.length ? 'mega' : (item.children?.length ? 'dropdown' : 'link'));
    const badge = item.badge ? <span className="ml-1 rounded-full bg-gold-500 text-white text-[9px] px-1.5 py-px tracking-normal">{item.badge}</span> : null;
    const linkProps = item.new_tab ? { target: '_blank', rel: 'noopener' } : {};
    const base = `relative inline-flex items-center gap-1 h-10 px-3 rounded-full transition-colors duration-300 ${open || current ? 'text-ink-900 bg-ink-900/[0.05]' : 'text-ink-800 hover:text-ink-900 hover:bg-ink-900/[0.04]'}`;

    if (type === 'link') {
        return (
            <SmartLink href={item.url} {...linkProps} className={base} {...(hover ? { onMouseEnter: onCloseAll } : {})}>
                {item.label}{badge}
            </SmartLink>
        );
    }

    return (
        <div className="flex items-center" {...(hover ? { onMouseEnter: onOpen } : {})}>
            {item.url ? (
                <a href={item.url} {...linkProps} className={`${base} pr-1.5`}>{item.label}{badge}</a>
            ) : (
                <span className={`${base} pr-1.5`}>{item.label}{badge}</span>
            )}
            <button type="button" onClick={onToggle} aria-label={`Toggle ${item.label} menu`} aria-expanded={open} className="-ml-1 grid place-items-center w-6 h-6 rounded-full text-ink-700 hover:text-ink-900">
                <Icon name="chevronDown" className={`w-3 h-3 transition-transform duration-300 ${open ? 'rotate-180' : ''}`} strokeWidth={2} />
            </button>
        </div>
    );
}

// Full-width panel under the bar. Dropdown items list their children as large
// links; mega items keep their columns.
function MegaPanel({ item, onClose }) {
    const type = item.type || (item.columns?.length ? 'mega' : (item.children?.length ? 'dropdown' : 'link'));
    const linkCls = 'group/l flex items-center justify-between gap-4 py-2.5 border-b border-ink-900/[0.06] text-[15px] text-ink-800 hover:text-ink-900';
    const arrow = <Icon name="chevronRight" className="w-3.5 h-3.5 opacity-0 -translate-x-2 transition duration-300 group-hover/l:opacity-100 group-hover/l:translate-x-0" strokeWidth={2} />;

    return (
        <div className="hidden lg:block absolute inset-x-0 top-full pop-in">
            <div className="bg-gold-50 border-t border-ink-900/5 shadow-[0_40px_80px_-40px_rgb(0_0_0/0.35)]">
                <div className="mx-auto max-w-[1440px] px-10 py-10 grid grid-cols-12 gap-10">
                    <div className="col-span-4 lg:col-span-3">
                        <p className="eyebrow">Explore</p>
                        <p className="display text-4xl mt-3">{item.label}</p>
                        {item.url && (
                            <a href={item.url} onClick={onClose} className="lux-arrow mt-6 inline-flex items-center gap-2 text-[13px] font-medium tracking-[0.06em] uppercase text-ink-900">
                                <span className="lux-link lux-link-on">Shop all {item.label}</span>
                                <Icon name="chevronRight" className="w-3.5 h-3.5" strokeWidth={2} />
                            </a>
                        )}
                    </div>
                    <div className="col-span-8 lg:col-span-9">
                        {type === 'mega' ? (
                            <div className="grid gap-8" style={{ gridTemplateColumns: `repeat(${Math.min(4, Math.max(1, item.columns?.length || 1))}, minmax(0,1fr))` }}>
                                {(item.columns || []).map((col, i) => (
                                    <div key={i}>
                                        {col.heading && <p className="eyebrow mb-3">{col.heading}</p>}
                                        <ul>
                                            {col.links.map((l, j) => (
                                                <li key={j}>
                                                    <SmartLink href={l.url} onClick={onClose} {...(l.new_tab ? { target: '_blank', rel: 'noopener' } : {})} className={linkCls}>
                                                        {l.label}{arrow}
                                                    </SmartLink>
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <ul className="grid grid-cols-2 lg:grid-cols-3 gap-x-10">
                                {(item.children || []).map((child, i) => (
                                    <li key={i}>
                                        <SmartLink href={child.url} onClick={onClose} {...(child.new_tab ? { target: '_blank', rel: 'noopener' } : {})} className={linkCls}>
                                            {child.label}{arrow}
                                        </SmartLink>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
