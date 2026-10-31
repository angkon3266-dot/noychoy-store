import { useEffect, useRef, useState } from 'react';
import { fetchJson } from './format';
import Icon from './Icons';
import { trapTab } from './focusTrap';

// The checkout spin-and-win wheel (owner, 2026-10-31). Slices and odds are
// set in Admin → Offers; the server draws the slice and mints the prize, and
// this only animates the wheel to where the server says it stopped. A prize
// is already on the cart by the time the wheel starts turning — `onDone`
// re-reads the page's totals once it has stopped, so the saving appears as
// the wheel settles rather than before it.

const SPIN_MS = 5200;
const QUICK_MS = 900;
const BULBS = 20;

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

function Wheel({ segments, rotation, duration, size = 300, pointer = true }) {
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
                            <g transform={`rotate(${mid})`}>
                                <text x="0" y="-60" transform="rotate(-90 0 -60)" textAnchor="middle" dominantBaseline="central"
                                      fill={inkFor(s.color)} fontSize={fs} fontWeight="700" letterSpacing="0.3"
                                      style={{ fontFamily: 'inherit' }}>
                                    {label}
                                </text>
                            </g>
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

export default function SpinWheel({ spin, url, onDone }) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [rotation, setRotation] = useState(0);
    const [duration, setDuration] = useState(0);
    // The result shown in the dialog once the wheel has stopped; the card on
    // the page reads the server's copy (`spin.result`) after the reload.
    const [landed, setLanded] = useState(null);
    const [error, setError] = useState(null);
    const panelRef = useRef(null);
    const timer = useRef(null);

    const result = spin.result;
    const segments = spin.segments;
    const seg = 360 / segments.length;

    // A spin already taken shows where it stopped.
    useEffect(() => {
        if (result && !busy && !landed) setRotation(360 - (result.index + 0.5) * seg);
    }, [result?.index, seg]);

    useEffect(() => () => clearTimeout(timer.current), []);

    useEffect(() => {
        if (!open) return;
        panelRef.current?.focus();
        const esc = (e) => e.key === 'Escape' && !busy && setOpen(false);
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, [open, busy]);

    const go = async () => {
        if (busy || result) return;
        setBusy(true);
        setError(null);
        let res;
        try {
            res = await fetchJson(url, { method: 'POST' });
        } catch (e) {
            setBusy(false);
            setError('Could not spin right now — please try again.');
            return;
        }
        if (!res.ok) {
            setBusy(false);
            if (res.result) { setLanded(res.result); onDone?.(); }
            else setError(res.message || 'The wheel is not available right now.');
            return;
        }

        const quick = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        const ms = quick ? QUICK_MS : SPIN_MS;
        // Somewhere inside the slice, not dead centre — a wheel that always
        // stops on the middle of a slice looks rigged.
        const jitter = (Math.random() - 0.5) * seg * 0.7;
        const target = 360 - (res.index + 0.5) * seg - jitter;
        const turns = quick ? 1 : 7;
        setDuration(ms);
        setRotation((r) => r + turns * 360 + ((((target - r) % 360) + 360) % 360));

        timer.current = setTimeout(() => {
            setLanded(res.result);
            setBusy(false);
            onDone?.();
        }, ms + 150);
    };

    const shown = landed || result;

    return (
        <>
            {/* The card on the page: an invitation before the spin, the prize after it. */}
            <div className="relative overflow-hidden rounded-2xl bg-ink-900 text-white p-4 sm:p-5 shadow-[0_20px_50px_-25px_rgb(0_0_0/0.6)]">
                <div className="flex items-center gap-4">
                    <button type="button" onClick={() => setOpen(true)} className="shrink-0 rounded-full focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-300" aria-label={shown ? 'See the wheel' : 'Open the spin wheel'}>
                        <span className={`block ${shown ? '' : 'slow-spin'}`}>
                            <Wheel segments={segments} rotation={shown ? 360 - (shown.index + 0.5) * seg : 0} duration={0} size={76} pointer={false} />
                        </span>
                    </button>
                    <div className="min-w-0 flex-1">
                        {shown ? (
                            <>
                                <p className="font-display text-xl leading-tight">
                                    {shown.won ? <>You won <span className="text-gold-300">{shown.label}</span>!</> : 'No prize this time'}
                                </p>
                                <p className="mt-1 text-xs text-white/70">
                                    {shown.won
                                        ? (shown.applied
                                            ? <span className="inline-flex items-center gap-1 text-success-200"><Icon name="check" className="w-3.5 h-3.5" />Applied to this order — code {shown.code}</span>
                                            : <>Code <strong className="font-mono text-white">{shown.code}</strong>{shown.note ? ` · ${shown.note}` : ''}</>)
                                        : 'Thanks for playing — the offers on your order still apply.'}
                                </p>
                            </>
                        ) : (
                            <>
                                <p className="font-display text-xl leading-tight">{spin.title}</p>
                                <p className="mt-1 text-xs text-white/70">{spin.blocked || spin.subtitle}</p>
                                {!spin.blocked && (
                                    <button type="button" onClick={() => setOpen(true)} className="mt-2.5 inline-flex items-center gap-1.5 rounded-full bg-gold-400 px-4 py-1.5 text-sm font-semibold text-ink-900 hover:bg-gold-300">
                                        <Icon name="gift" className="w-4 h-4" />Spin now
                                    </button>
                                )}
                            </>
                        )}
                    </div>
                </div>
            </div>

            {open && (
                <div className="fixed inset-0 z-[70] flex items-center justify-center p-4">
                    <div className="absolute inset-0 bg-ink-900/70 backdrop-blur-[3px]" onClick={() => !busy && setOpen(false)} />
                    <div
                        ref={panelRef}
                        tabIndex={-1}
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby="spin-title"
                        onKeyDown={(e) => trapTab(e, panelRef.current)}
                        className="pop-in relative w-full max-w-sm overflow-hidden rounded-[28px] bg-gradient-to-b from-ink-900 to-[#1c1407] px-5 pt-6 pb-6 text-center text-white shadow-2xl outline-none"
                    >
                        {landed?.won && <Confetti />}
                        <button type="button" onClick={() => setOpen(false)} disabled={busy} aria-label="Close"
                                className="absolute right-3 top-3 grid h-9 w-9 place-items-center rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-40">
                            <Icon name="close" className="w-4 h-4" />
                        </button>
                        <h2 id="spin-title" className="font-display text-3xl tracking-[-0.01em]">{spin.title}</h2>
                        <p className="mx-auto mt-1 max-w-[18rem] text-xs text-white/70">{spin.subtitle}</p>

                        <div className="relative mx-auto mt-5 w-full max-w-[300px]">
                            <Wheel segments={segments} rotation={rotation} duration={duration} />
                            {!shown && (
                                <button type="button" onClick={go} disabled={busy}
                                        className="absolute left-1/2 top-[50.6%] h-[19%] w-[19%] -translate-x-1/2 -translate-y-1/2 rounded-full text-[11px] font-extrabold tracking-wide text-ink-900 disabled:cursor-default">
                                    {busy ? '' : 'SPIN'}
                                </button>
                            )}
                        </div>

                        <div className="mt-5 min-h-[3.5rem]" aria-live="polite">
                            {error && <p className="text-sm text-danger-200">{error}</p>}
                            {!shown && !error && (
                                <button type="button" onClick={go} disabled={busy} className="w-full rounded-full bg-gold-400 py-3 text-base font-bold text-ink-900 hover:bg-gold-300 disabled:opacity-70">
                                    {busy ? 'Spinning…' : 'Spin the wheel'}
                                </button>
                            )}
                            {shown && (
                                <>
                                    <p className="font-display text-2xl">
                                        {shown.won ? <>🎉 You won <span className="text-gold-300">{shown.label}</span></> : 'Better luck next time!'}
                                    </p>
                                    <p className="mt-1 text-xs text-white/70">
                                        {shown.won
                                            ? (shown.applied ? 'Applied to this order automatically.' : (shown.note || `Your code: ${shown.code}`))
                                            : 'Your order’s offers still apply.'}
                                    </p>
                                    <button type="button" onClick={() => setOpen(false)} className="mt-4 w-full rounded-full bg-white py-3 text-base font-semibold text-ink-900 hover:bg-mist-100">
                                        Continue to checkout
                                    </button>
                                </>
                            )}
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
