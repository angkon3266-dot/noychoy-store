import { useEffect, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { fetchJson } from './format';
import Icon from './Icons';
import { trapTab } from './focusTrap';
import { useCart } from './CartContext';

// The spin-and-win wheel (owner, 2026-10-31). Slices and odds are set in
// Admin → Offers; the server draws the slice and mints the prize, and the
// browser only animates the wheel to where the server says it stopped.
//
// One dialog for the whole storefront, owned by <SpinHost> in the Layout. It
// opens by itself once per visit while she browses (the popup), and from
// anywhere that calls openSpinWheel(): the menu, the floating button, the
// homepage band, the cart and the checkout cards. Everything reads the one
// state the server shares as `chrome.spin` (the checkout also gets its own
// copy, resolved before its totals).

const SPIN_MS = 5200;
const QUICK_MS = 900;
const BULBS = 20;
const OPEN_EVENT = 'spin-wheel:open';
export const DONE_EVENT = 'spin-wheel:done';

// What a finished spin re-reads: the shared chrome and cart, plus whatever
// the cart page or the checkout prices from (keys a page lacks are ignored).
const RELOAD_ONLY = [
    'chrome', 'cart', 'ladder', 'flash', 'errors',
    'items', 'summary', 'coupon', 'freeShipping', 'gift', 'loyalty', 'spin',
    'giftBar', 'freeBar', 'offersPanel', 'memberUsage', 'memberNudge', 'suggestions',
];

export function openSpinWheel() {
    window.dispatchEvent(new Event(OPEN_EVENT));
}

/** The wheel state, from the shared chrome. */
export function useSpin() {
    return usePage().props.chrome?.spin || null;
}

// Readable text on any slice colour the owner picks.
const inkFor = (hex) => {
    const n = parseInt(String(hex || '#000000').slice(1), 16);
    const [r, g, b] = [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    return (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.6 ? '#111827' : '#FFFFFF';
};

// Degrees clockwise from twelve o'clock, as the pointer sees them.
const pt = (r, deg) => {
    const a = (deg * Math.PI) / 180;
    return [r * Math.sin(a), -r * Math.cos(a)];
};

const restingAngle = (spin) => (spin?.result ? 360 - (spin.result.index + 0.5) * (360 / spin.segments.length) : 0);

export function Wheel({ segments, rotation = 0, duration = 0, size = 300, pointer = true, labels = true }) {
    const n = segments.length;
    const seg = 360 / n;
    const R = 100;

    return (
        <svg viewBox="-115 -118 230 233" width={size} height={size} className="block max-w-full h-auto" aria-hidden="true">
            <defs>
                <radialGradient id="sw-rim" cx="50%" cy="50%" r="50%">
                    <stop offset="80%" stopColor="#E9C46A" />
                    <stop offset="100%" stopColor="#8A6A1F" />
                </radialGradient>
                <radialGradient id="sw-hub" cx="40%" cy="35%" r="70%">
                    <stop offset="0%" stopColor="#FFF4D0" />
                    <stop offset="60%" stopColor="#D6A93F" />
                    <stop offset="100%" stopColor="#7A5A16" />
                </radialGradient>
            </defs>

            {/* Rim with marquee bulbs — still, so the slices carry the motion. */}
            <circle r="112" fill="url(#sw-rim)" />
            <circle r="104" fill="#111827" />
            {Array.from({ length: BULBS }, (_, i) => {
                const [x, y] = pt(108, (360 / BULBS) * i);
                return <circle key={i} cx={x} cy={y} r="2.6" fill="#FFF7D6" className={`wheel-bulb ${i % 2 ? 'wheel-bulb-alt' : ''}`} />;
            })}

            <g style={{ transform: `rotate(${rotation}deg)`, transition: duration ? `transform ${duration}ms cubic-bezier(0.15, 0.85, 0.12, 1)` : 'none' }}>
                {segments.map((s, i) => {
                    const a0 = i * seg;
                    const a1 = a0 + seg;
                    const [x0, y0] = pt(R, a0);
                    const [x1, y1] = pt(R, a1);
                    const large = seg > 180 ? 1 : 0;
                    const mid = a0 + seg / 2;
                    const label = s.label || '';
                    const fs = label.length > 14 ? 6.5 : label.length > 9 ? 8 : 9.5;
                    return (
                        <g key={i}>
                            <path d={`M0 0 L${x0} ${y0} A${R} ${R} 0 ${large} 1 ${x1} ${y1} Z`} fill={s.color} stroke="#E9C46A" strokeWidth="0.8" />
                            {/* No labels on the thumbnail wheels: unreadable at
                                that size, and a screen reader would hear every
                                slice as the button's text. */}
                            {labels && (
                                <g transform={`rotate(${mid})`}>
                                    <text x="0" y="-60" transform="rotate(-90 0 -60)" textAnchor="middle" dominantBaseline="central"
                                          fill={inkFor(s.color)} fontSize={fs} fontWeight="700" letterSpacing="0.3"
                                          style={{ fontFamily: 'inherit' }}>
                                        {label}
                                    </text>
                                </g>
                            )}
                        </g>
                    );
                })}
            </g>

            <circle r="17" fill="url(#sw-hub)" stroke="#7A5A16" strokeWidth="1" />

            {/* The pointer, at twelve o'clock. */}
            {pointer && <path d="M0 -92 L-9 -117 L9 -117 Z" fill="#DC2626" stroke="#FFF7D6" strokeWidth="1.5" strokeLinejoin="round" />}
        </svg>
    );
}

function Confetti() {
    const colors = ['#E9C46A', '#DC2626', '#111827', '#F5EBD7', '#16A34A', '#2563EB'];
    return (
        <div className="pointer-events-none absolute inset-0 overflow-hidden" aria-hidden="true">
            {Array.from({ length: 36 }, (_, i) => (
                <span
                    key={i}
                    className="confetti-piece"
                    style={{
                        left: `${(i * 37) % 100}%`,
                        background: colors[i % colors.length],
                        animationDelay: `${(i % 9) * 0.08}s`,
                        '--drift': `${((i * 53) % 120) - 60}px`,
                        '--spin': `${(i * 97) % 720}deg`,
                    }}
                />
            ))}
        </div>
    );
}

/** One line on what she won and where it stands. */
function prizeLine(r) {
    if (!r.won) return 'Thanks for playing — the offers on your order still apply.';
    if (r.applied) return r.gift ? `${r.gift.name} is in your bag, free.` : 'Applied to your order automatically.';
    return r.note || `Your code: ${r.code}`;
}

function GiftThumb({ gift, className = '' }) {
    if (!gift?.image) return null;
    return <img src={gift.image} alt="" className={`rounded-xl object-cover bg-white/10 ${className}`} />;
}

const ss = {
    get(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { window.sessionStorage.setItem(k, String(v)); } catch (e) { /* private mode */ } },
};

/**
 * The one dialog, the popup, and the spin itself. Mounted once, in the
 * persistent Layout, so it survives navigations and its timer keeps running
 * while she browses.
 */
export function SpinHost() {
    const { url, component, props } = usePage();
    const spin = useSpin();
    const { refresh, drawer } = useCart();
    const inBag = (props.cart?.count || 0) > 0;
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [rotation, setRotation] = useState(0);
    const [duration, setDuration] = useState(0);
    // The result shown once the wheel has stopped — the server's copy
    // (`spin.result`) only arrives with the reload that follows.
    const [landed, setLanded] = useState(null);
    const [error, setError] = useState(null);
    const panelRef = useRef(null);
    const timer = useRef(null);
    const popTimer = useRef(null);
    const drawerOpen = useRef(drawer);
    drawerOpen.current = drawer;

    const result = landed || spin?.result || null;
    const seg = spin ? 360 / spin.segments.length : 0;

    useEffect(() => {
        const show = () => { setError(null); setOpen(true); };
        window.addEventListener(OPEN_EVENT, show);
        return () => window.removeEventListener(OPEN_EVENT, show);
    }, []);

    // A spin already taken shows where it stopped.
    useEffect(() => {
        if (spin?.result && !busy && !landed) {
            setDuration(0);
            setRotation(restingAngle(spin));
        }
    }, [spin?.result?.index, seg]);

    useEffect(() => () => { clearTimeout(timer.current); clearTimeout(popTimer.current); }, []);

    // The popup: once per visit, after the Nth page or N seconds of browsing,
    // whichever comes first. Never over the checkout form — the wheel has its
    // own card there — and never on top of the open bag.
    //
    // Never on the first page of a visit either, whatever the delay says. An
    // ad lands on a product page, and on a phone the 31 Oct 2026 audit had
    // the wheel covering that page within twenty seconds, before she had
    // read it. From the second page on, the owner's timing applies; a
    // shopper who puts something in the bag on the first page has shown she
    // is staying, so she may see it there.
    useEffect(() => {
        clearTimeout(popTimer.current);
        if (!spin?.popup || spin.result || spin.blocked || landed) return;
        if (component === 'Checkout' || ss.get('spin_popup_seen')) return;

        const pages = Number(ss.get('spin_pages') || 0) + 1;
        ss.set('spin_pages', pages);
        const start = Number(ss.get('spin_started') || 0) || Date.now();
        ss.set('spin_started', start);
        if (pages < 2 && !inBag) return;

        const pop = () => {
            if (drawerOpen.current) {
                popTimer.current = setTimeout(pop, 5000);
                return;
            }
            ss.set('spin_popup_seen', 1);
            setError(null);
            setOpen(true);
        };
        const wait = pages >= spin.popup.pages ? 1500 : Math.max(1500, start + spin.popup.delay * 1000 - Date.now());
        popTimer.current = setTimeout(pop, wait);
    }, [url, !!spin?.popup, !!spin?.result, !!spin?.blocked, inBag]);

    useEffect(() => {
        if (!open) return;
        panelRef.current?.focus();
        ss.set('spin_popup_seen', 1);
        const esc = (e) => e.key === 'Escape' && !busy && setOpen(false);
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [open, busy]);

    if (!spin || !open) return null;

    const finish = () => {
        refresh?.();
        router.reload({ only: RELOAD_ONLY, preserveScroll: true, onSuccess: () => window.dispatchEvent(new Event(DONE_EVENT)) });
    };

    const go = async () => {
        if (busy || result) return;
        setBusy(true);
        setError(null);
        let res;
        try {
            res = await fetchJson(spin.url, { method: 'POST' });
        } catch (e) {
            setBusy(false);
            setError('Could not spin right now — please try again.');
            return;
        }
        if (!res.ok) {
            setBusy(false);
            if (res.result) { setLanded(res.result); finish(); }
            else setError(res.message || 'The wheel is not available right now.');
            return;
        }

        const quick = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        const ms = quick ? QUICK_MS : SPIN_MS;
        // Somewhere inside the slice, not dead centre — a wheel that always
        // stops on the middle of a slice looks rigged.
        const jitter = (Math.random() - 0.5) * seg * 0.7;
        const target = 360 - (res.index + 0.5) * seg - jitter;
        setDuration(ms);
        setRotation((r) => r + (quick ? 1 : 7) * 360 + ((((target - r) % 360) + 360) % 360));

        timer.current = setTimeout(() => {
            setLanded(res.result);
            setBusy(false);
            finish();
        }, ms + 150);
    };

    return (
        <div className="fixed inset-0 z-[80] flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-ink-900/70 backdrop-blur-[3px]" onClick={() => !busy && setOpen(false)} />
            <div
                ref={panelRef}
                tabIndex={-1}
                role="dialog"
                aria-modal="true"
                aria-labelledby="spin-title"
                onKeyDown={(e) => trapTab(e, panelRef.current)}
                className="pop-in relative w-full max-w-sm max-h-[calc(100vh-2rem)] overflow-y-auto overflow-x-hidden rounded-[28px] bg-gradient-to-b from-ink-900 to-[#1c1407] px-5 pt-6 pb-6 text-center text-white shadow-2xl outline-none"
            >
                {landed?.won && <Confetti />}
                <button type="button" onClick={() => setOpen(false)} disabled={busy} aria-label="Close"
                        className="absolute right-3 top-3 z-10 grid h-9 w-9 place-items-center rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-40">
                    <Icon name="close" className="w-4 h-4" />
                </button>
                <h2 id="spin-title" className="font-display text-3xl tracking-[-0.01em]">{spin.title}</h2>
                <p className="mx-auto mt-1 max-w-[18rem] text-xs text-white/70">{spin.subtitle}</p>

                <div className="relative mx-auto mt-5 w-full max-w-[300px]">
                    <Wheel segments={spin.segments} rotation={rotation} duration={duration} />
                    {!result && !spin.blocked && (
                        <button type="button" onClick={go} disabled={busy}
                                className="absolute left-1/2 top-[50.6%] h-[19%] w-[19%] -translate-x-1/2 -translate-y-1/2 rounded-full text-[11px] font-extrabold tracking-wide text-ink-900 disabled:cursor-default">
                            {busy ? '' : 'SPIN'}
                        </button>
                    )}
                </div>

                <div className="mt-5 min-h-[3.5rem]" aria-live="polite">
                    {error && <p className="text-sm text-danger-200">{error}</p>}
                    {!result && spin.blocked && <p className="text-sm text-white/80">{spin.blocked}</p>}
                    {!result && !spin.blocked && !error && (
                        <button type="button" onClick={go} disabled={busy} className="w-full rounded-full bg-gold-400 py-3 text-base font-bold text-ink-900 hover:bg-gold-300 disabled:opacity-70">
                            {busy ? 'Spinning…' : 'Spin the wheel'}
                        </button>
                    )}
                    {result && (
                        <>
                            <p className="font-display text-2xl">
                                {result.won ? <>🎉 You won <span className="text-gold-300">{result.label}</span></> : 'Better luck next time!'}
                            </p>
                            {result.gift && (
                                <div className="mx-auto mt-3 flex max-w-[16rem] items-center gap-3 rounded-2xl bg-white/10 p-2 text-left">
                                    <GiftThumb gift={result.gift} className="h-14 w-14 shrink-0" />
                                    <span className="min-w-0 text-sm font-medium">{result.gift.name}</span>
                                </div>
                            )}
                            <p className="mt-2 text-xs text-white/70">{prizeLine(result)}</p>
                            <button type="button" onClick={() => setOpen(false)} className="mt-4 w-full rounded-full bg-white py-3 text-base font-semibold text-ink-900 hover:bg-mist-100">
                                {component === 'Checkout' ? 'Continue to checkout' : 'Continue shopping'}
                            </button>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

/**
 * The invitation (before a spin) or the prize (after it), as a card. Used at
 * the top of the checkout and the cart, and as the homepage band (`band`).
 */
export function SpinCard({ spin: own, band = false }) {
    const shared = useSpin();
    const spin = own || shared;
    if (!spin) return null;
    const r = spin.result;
    const size = band ? 132 : 76;

    return (
        <div className={`relative overflow-hidden rounded-2xl bg-ink-900 text-white shadow-[0_20px_50px_-25px_rgb(0_0_0/0.6)] ${band ? 'p-6 sm:p-8' : 'p-4 sm:p-5'}`}>
            <div className={`flex items-center ${band ? 'gap-6 sm:gap-10' : 'gap-4'}`}>
                <button type="button" onClick={openSpinWheel} className="shrink-0 rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-300" aria-label={r ? 'See the wheel' : 'Open the spin wheel'}>
                    <span className={`block ${r ? '' : 'slow-spin'}`}>
                        <Wheel segments={spin.segments} rotation={restingAngle(spin)} size={size} pointer={false} />
                    </span>
                </button>
                <div className="min-w-0 flex-1">
                    {r ? (
                        <>
                            <p className={`font-display leading-tight ${band ? 'text-3xl' : 'text-xl'}`}>
                                {r.won ? <>You won <span className="text-gold-300">{r.label}</span>!</> : 'No prize this time'}
                            </p>
                            <p className="mt-1 text-xs text-white/70">
                                {r.won && r.applied
                                    ? <span className="inline-flex items-center gap-1 text-success-200"><Icon name="check" className="w-3.5 h-3.5 shrink-0" />{prizeLine(r)}</span>
                                    : prizeLine(r)}
                            </p>
                            {r.gift && !r.applied && r.gift.url && (
                                <a href={r.gift.url} className="mt-2 inline-flex text-xs font-semibold text-gold-300 underline">See your gift</a>
                            )}
                        </>
                    ) : (
                        <>
                            {band && <p className="eyebrow text-gold-300">One free spin per visit</p>}
                            <p className={`font-display leading-tight ${band ? 'text-3xl sm:text-4xl mt-1' : 'text-xl'}`}>{spin.title}</p>
                            <p className="mt-1 text-xs sm:text-sm text-white/70">{spin.blocked || spin.subtitle}</p>
                            {!spin.blocked && (
                                <button type="button" onClick={openSpinWheel} className={`mt-3 inline-flex items-center gap-1.5 rounded-full bg-gold-400 font-semibold text-ink-900 hover:bg-gold-300 ${band ? 'px-6 py-2.5 text-base' : 'px-4 py-1.5 text-sm'}`}>
                                    <Icon name="gift" className="w-4 h-4" />Spin now
                                </button>
                            )}
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

/** The homepage band: the card, in the page's own width. */
export function SpinBand() {
    const spin = useSpin();
    if (!spin) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 sm:px-6 py-8 sm:py-12" aria-label={spin.title}>
            <SpinCard band />
        </section>
    );
}

/** The floating launcher: a small turning wheel, until she has spun. */
export function SpinFab() {
    const spin = useSpin();
    if (!spin || spin.result || spin.blocked) return null;
    return (
        <button
            type="button"
            onClick={openSpinWheel}
            className="relative flex h-12 w-12 sm:h-14 sm:w-14 items-center justify-center rounded-full bg-ink-900 shadow-lg ring-2 ring-gold-400 hover:scale-105 transition"
            title={spin.title}
            aria-label={`WIN — ${spin.title}: spin the wheel`}
        >
            <span className="block slow-spin"><Wheel segments={spin.segments} size={44} pointer={false} labels={false} /></span>
            <span aria-hidden="true" className="absolute -top-1 -right-1 rounded-full bg-danger-600 px-1.5 text-[10px] font-bold leading-5 text-white">WIN</span>
        </button>
    );
}

/** A menu entry: "Spin & win", for the header and the drawer. */
export function SpinMenuButton({ className = '', onClick }) {
    const spin = useSpin();
    if (!spin || spin.result) return null;
    return (
        <button type="button" onClick={() => { onClick?.(); openSpinWheel(); }} className={className}>
            <span className="inline-block w-5 h-5 shrink-0 slow-spin"><Wheel segments={spin.segments} size={20} pointer={false} labels={false} /></span>
            <span>{spin.title}</span>
        </button>
    );
}

export default SpinCard;
