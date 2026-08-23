import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import SmartLink from '../SmartLink';
import Icon from '../Icons';
import SearchBox from './SearchBox';
import NotificationsBell from './NotificationsBell';
import { useCart } from '../CartContext';
import { useMobileNav } from './MobileDrawer';

export default function Header() {
    const { props } = usePage();
    const chrome = props.chrome || {};
    const { logo = {}, menuIcon = {}, menu = {}, urls = {}, storeName } = chrome;
    const { count, openDrawer } = useCart();
    const nav = useMobileNav();
    const [mSearch, setMSearch] = useState(false);
    const [imgOk, setImgOk] = useState(true);

    const logoAlignClass = logo.align === 'center'
        ? 'absolute left-1/2 -translate-x-1/2'
        : (logo.align === 'right' ? 'ml-auto' : '');

    return (
        <header className="sticky top-0 z-40 bg-gold-50/95 backdrop-blur border-b border-gold-200">
            <div className="mx-auto max-w-7xl px-4">
                <div className="relative flex h-16 items-center gap-2">
                    {/* Mobile menu toggle — custom icon with guaranteed SVG fallback */}
                    <button
                        onClick={nav.toggle}
                        className="md:hidden p-2 -ml-1 text-ink-900"
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
                            <Icon
                                name="menu"
                                className="w-7 h-7 transition-transform duration-300"
                                style={{ transform: `rotate(${nav.open ? menuIcon.rotation : 0}deg)` }}
                            />
                        )}
                    </button>

                    {/* Logo */}
                    <SmartLink href={urls.home || '/'} className={`shrink-0 ${logoAlignClass}`}>
                        {logo.desktop || logo.mobile ? (
                            <>
                                {logo.desktop && <img src={logo.desktop} alt={storeName} height={logo.hDesktop} decoding="async" fetchPriority="high" className="logo-d w-auto hidden md:block" />}
                                {logo.mobile && <img src={logo.mobile} alt={storeName} height={logo.hMobile} decoding="async" fetchPriority="high" className="logo-m w-auto md:hidden" />}
                            </>
                        ) : (
                            <span className="logo-m md:logo-d inline-flex items-center font-display font-bold tracking-wide text-gold-700" style={{ fontSize: `calc(${logo.hMobile || 32}px * 0.55)` }}>
                                {storeName}
                            </span>
                        )}
                    </SmartLink>

                    {/* Optional centre image (mobile only) */}
                    {logo.center && (
                        <a href={logo.centerLink} className="md:hidden absolute left-1/2 -translate-x-1/2">
                            <img src={logo.center} alt="" height={logo.centerH} decoding="async" className="logo-center w-auto" />
                        </a>
                    )}

                    {/* Desktop nav */}
                    <nav className="hidden md:flex items-center gap-6 text-sm font-medium" aria-label="Main">
                        {(menu.items || []).map((item, i) => (
                            <DesktopItem key={i} item={item} trigger={menu.trigger} />
                        ))}
                        {menu.cta && (
                            <a href={menu.cta.url} className="rounded-full bg-gold-600 text-white px-4 py-1.5 hover:bg-gold-700">{menu.cta.label}</a>
                        )}
                    </nav>

                    <div className="flex items-center gap-1 ml-auto">
                        {menu.showSearch && (
                            <button type="button" onClick={() => setMSearch(!mSearch)} className="md:hidden p-2 hover:text-gold-700" aria-label="Search">
                                <Icon name="search" />
                            </button>
                        )}
                        {menu.showSearch && (
                            <div className="hidden lg:block">
                                <SearchBox compact />
                            </div>
                        )}
                        {props.customer ? (
                            <>
                                <NotificationsBell />
                                <a href={urls.account} className="p-2 hover:text-gold-700" title="My account" aria-label="My account">
                                    <Icon name="user" />
                                </a>
                            </>
                        ) : (
                            <a href={urls.login} className="p-2 hover:text-gold-700" title="Login" aria-label="Sign in">
                                <Icon name="user" />
                            </a>
                        )}
                        <button type="button" onClick={openDrawer} className="relative p-2 hover:text-gold-700" title="Cart" aria-label={count > 0 ? `Cart, ${count} item${count === 1 ? '' : 's'}` : 'Cart'}>
                            <Icon name="cart" />
                            {count > 0 && (
                                <span aria-hidden="true" className="absolute -top-0.5 -right-0.5 badge bg-gold-600 text-white px-1.5 min-w-5 justify-center">{count}</span>
                            )}
                        </button>
                    </div>
                </div>

                {/* Mobile collapsible search bar */}
                {menu.showSearch && mSearch && (
                    <div className="md:hidden pb-3">
                        <SearchBox autoFocus onNavigate={() => setMSearch(false)} />
                    </div>
                )}
            </div>
        </header>
    );
}

function DesktopItem({ item, trigger }) {
    const [open, setOpen] = useState(false);
    const type = item.type || (item.columns?.length ? 'mega' : (item.children?.length ? 'dropdown' : 'link'));

    const badge = item.badge ? <span className="badge bg-gold-600 text-white text-[9px]">{item.badge}</span> : null;
    const linkProps = item.new_tab ? { target: '_blank', rel: 'noopener' } : {};

    if (type === 'link') {
        return (
            <SmartLink href={item.url} {...linkProps} className="hover:text-gold-700 flex items-center gap-1">
                {item.label}{badge}
            </SmartLink>
        );
    }

    const hoverProps = trigger === 'hover'
        ? { onMouseEnter: () => setOpen(true), onMouseLeave: () => setOpen(false) }
        : {};

    return (
        <div className={type === 'mega' ? 'static' : 'relative'} {...hoverProps}>
            <div className="flex items-center gap-0.5 hover:text-gold-700">
                {item.url ? (
                    <a href={item.url} {...linkProps} className="flex items-center gap-1">{item.label}{badge}</a>
                ) : (
                    <span className="flex items-center gap-1">{item.label}{badge}</span>
                )}
                <button type="button" onClick={() => setOpen(!open)} aria-label={`Toggle ${item.label} menu`} className="p-1 -ml-0.5">
                    <Icon name="chevronDown" className={`w-3.5 h-3.5 opacity-60 transition-transform ${open ? 'rotate-180' : ''}`} strokeWidth={2} />
                </button>
            </div>

            {open && type === 'mega' && (
                <div className="absolute left-0 right-0 top-full pt-3 z-50">
                    <div
                        className="mx-auto max-w-7xl rounded-xl border border-gold-100 bg-white shadow-xl p-6 grid gap-6"
                        style={{ gridTemplateColumns: `repeat(${Math.min(5, Math.max(1, item.columns?.length || 1))}, minmax(0,1fr))` }}
                    >
                        {(item.columns || []).length ? item.columns.map((col, i) => (
                            <div key={i}>
                                {col.heading && <p className="font-display font-bold text-base text-gold-700 mb-2.5 tracking-wide">{col.heading}</p>}
                                <ul className="space-y-1.5">
                                    {col.links.map((l, j) => (
                                        <li key={j}>
                                            <SmartLink href={l.url} {...(l.new_tab ? { target: '_blank', rel: 'noopener' } : {})} className="text-sm text-ink-700/80 hover:text-gold-700">{l.label}</SmartLink>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )) : (
                            <a href={item.url} className="text-sm text-gold-700">View {item.label}</a>
                        )}
                    </div>
                </div>
            )}

            {open && type !== 'mega' && (
                <div className="absolute left-0 top-full pt-3 z-50 min-w-48">
                    <div className="rounded-lg border border-gold-100 bg-white shadow-lg py-2">
                        <SmartLink href={item.url} {...linkProps} className="block px-4 py-2 hover:bg-gold-50 font-medium">All {item.label}</SmartLink>
                        {(item.children || []).map((child, i) => (
                            <SmartLink key={i} href={child.url} {...(child.new_tab ? { target: '_blank', rel: 'noopener' } : {})} className="block px-4 py-2 hover:bg-gold-50 text-ink-700/80">{child.label}</SmartLink>
                        ))}
                    </div>
                </div>
            )}
        </div>
    );
}
