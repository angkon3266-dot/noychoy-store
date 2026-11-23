import { useMemo, useState } from 'react';
import Layout from '../Shared/Chrome/Layout';
import SmartLink from '../Shared/SmartLink';
import Icon from '../Shared/Icons';
import { useCart } from '../Shared/CartContext';
import { money } from '../Shared/format';
import { SKIN_TONES, shade, usePieceColours } from '../Shared/pieceColour';
import { MoreTools, PageHead, PieceSwatch, SizeChips, cartItem, needsSize, shell } from '../Shared/StyleKit';

/*
 * The bangle & ring stacker (Nov 2026; the idea is the Jhumka sample on
 * navira.ink). Pieces slide onto an illustrated hand: bangles and bracelets
 * round the wrist, rings onto the fingers. Each is drawn in the colour read
 * off its own photo, or from the owner's cut-out where there is one. The
 * whole stack goes into the bag in one tap, sizes and all — and the reward
 * ladder in the bag already takes more off the more pieces there are, so the
 * stack needs no discount of its own.
 */

const WRIST_MAX = 7;
const RING_MAX = 8;
const FINGERS = [
    { key: 'ring', label: 'Ring finger', x: 227, w: 36, top: 62 },
    { key: 'middle', label: 'Middle finger', x: 186, w: 38, top: 44 },
    { key: 'index', label: 'Index finger', x: 145, w: 36, top: 72 },
    { key: 'little', label: 'Little finger', x: 266, w: 31, top: 108 },
];
const FINGER_BASE = 218;

const isWrist = (p) => p.kind === 'bangle' || p.kind === 'bracelet';

export default function Stacker({ pieces, start }) {
    const { addSet } = useCart();
    const startPiece = pieces.find((p) => p.id === start);

    const [tab, setTab] = useState(startPiece && !isWrist(startPiece) ? 'rings' : 'wrist');
    const [skin, setSkin] = useState(SKIN_TONES[1]);
    const [stack, setStack] = useState(() => (startPiece ? place([], startPiece) : []));
    const [sizes, setSizes] = useState({});
    const [note, setNote] = useState(null);

    const byId = useMemo(() => Object.fromEntries(pieces.map((p) => [p.id, p])), [pieces]);
    const wristPieces = pieces.filter(isWrist);
    const ringPieces = pieces.filter((p) => p.kind === 'ring');
    const shown = tab === 'wrist' ? wristPieces : ringPieces;
    const colourOf = usePieceColours(pieces);

    const inStack = (id) => stack.some((s) => s.id === id);

    function place(current, piece) {
        if (isWrist(piece)) {
            if (current.filter((s) => s.slot === 'wrist').length >= WRIST_MAX) {
                setNote?.(`Seven on one wrist is plenty — take one off to add another.`);
                return current;
            }
            return [...current, { id: piece.id, slot: 'wrist' }];
        }
        const rings = current.filter((s) => s.slot !== 'wrist');
        if (rings.length >= RING_MAX) {
            setNote?.('That is every finger stacked — take a ring off first.');
            return current;
        }
        const finger = FINGERS.find((f) => rings.filter((s) => s.slot === f.key).length === 0)
            || FINGERS.find((f) => rings.filter((s) => s.slot === f.key).length < 2);
        return [...current, { id: piece.id, slot: finger.key }];
    }

    const toggle = (piece) => {
        setNote(null);
        setStack((cur) => (cur.some((s) => s.id === piece.id) ? cur.filter((s) => s.id !== piece.id) : place(cur, piece)));
    };

    const surprise = () => {
        setNote(null);
        const pick = (list, n) => [...list].filter((p) => p.available && !p.needs_page).sort(() => Math.random() - 0.5).slice(0, n);
        let next = [];
        pick(wristPieces, 3).forEach((p) => { next = place(next, p); });
        pick(ringPieces, 2).forEach((p) => { next = place(next, p); });
        setStack(next);
    };

    const moveRing = (id) => {
        setStack((cur) => cur.map((s) => {
            if (s.id !== id || s.slot === 'wrist') return s;
            const i = FINGERS.findIndex((f) => f.key === s.slot);
            return { ...s, slot: FINGERS[(i + 1) % FINGERS.length].key };
        }));
    };

    const chosen = stack.map((s) => byId[s.id]).filter(Boolean);
    const items = chosen.map((p) => cartItem(p, sizes[p.id])).filter(Boolean);
    const missing = chosen.filter((p) => needsSize(p) && !sizes[p.id]);
    const total = chosen.reduce((sum, p) => sum + (Number(p.price) || 0), 0);

    return (
        <div className={`${shell} pb-10`}>
            <PageHead
                eyebrow="Stack it"
                title="Build your stack"
                subtitle="Slide bangles onto the wrist and rings onto the fingers until it looks like you. Then put the whole stack in your bag in one tap — the more pieces, the more the reward ladder takes off."
            />

            <div className="grid lg:grid-cols-12 gap-8 lg:gap-12 items-start">
                {/* The hand */}
                <div className="lg:col-span-5 lg:sticky lg:top-28">
                    <div className="relative rounded-[32px] bg-gradient-to-b from-mist-50 to-mist-100 border border-ink-900/[0.06] overflow-hidden">
                        <Hand skin={skin} stack={stack} byId={byId} colourOf={colourOf} onRing={moveRing} />
                        {stack.length === 0 && (
                            <p className="absolute inset-x-0 bottom-6 text-center text-sm text-ink-700/70">Pick a piece to start your stack</p>
                        )}
                    </div>
                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-2" role="group" aria-label="Skin tone">
                            {SKIN_TONES.map((t) => (
                                <button key={t.key} type="button" onClick={() => setSkin(t)} aria-label={t.label} aria-pressed={skin.key === t.key}
                                        className={`h-8 w-8 rounded-full border-2 transition ${skin.key === t.key ? 'border-ink-900 scale-110' : 'border-white shadow'}`}
                                        style={{ background: t.base }} />
                            ))}
                        </div>
                        <div className="flex items-center gap-4 text-sm">
                            <button type="button" onClick={surprise} className="inline-flex items-center gap-1.5 text-ink-900 lux-link"><Icon name="sparkle" className="w-4 h-4" />Surprise me</button>
                            {stack.length > 0 && <button type="button" onClick={() => setStack([])} className="text-ink-700/70 hover:text-ink-900">Clear</button>}
                        </div>
                    </div>
                    {stack.some((s) => s.slot !== 'wrist') && <p className="mt-2 text-xs text-ink-700/60">Tap a ring on the hand to move it to another finger.</p>}
                </div>

                {/* The pieces */}
                <div className="lg:col-span-7">
                    <div role="tablist" aria-label="Pieces" className="inline-grid grid-cols-2 gap-1 rounded-full bg-mist-50 p-1 border border-ink-900/[0.06]">
                        {[['wrist', `Bangles & bracelets (${wristPieces.length})`], ['rings', `Rings (${ringPieces.length})`]].map(([key, label]) => (
                            <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setTab(key)}
                                    className={`min-h-10 rounded-full px-4 text-[13px] font-medium transition-colors duration-300 ${tab === key ? 'bg-ink-900 text-white' : 'text-ink-800 hover:bg-white'}`}>
                                {label}
                            </button>
                        ))}
                    </div>

                    {note && <p className="mt-4 text-sm text-danger-600" aria-live="polite">{note}</p>}

                    {shown.length === 0 ? (
                        <p className="mt-8 text-sm text-ink-700/70">None in stock right now — have a look at the other tab.</p>
                    ) : (
                        <ul className="mt-6 grid grid-cols-2 sm:grid-cols-3 gap-3">
                            {shown.map((p) => {
                                const on = inStack(p.id);
                                return (
                                    <li key={p.id}>
                                        <button type="button" onClick={() => toggle(p)} aria-pressed={on}
                                                className={`group w-full h-full text-left rounded-2xl border p-3 transition-colors duration-300 ${on ? 'border-ink-900 bg-white ring-1 ring-ink-900' : 'border-ink-900/10 bg-white hover:border-ink-900/50'}`}>
                                            <span className="relative block">
                                                <PieceSwatch piece={p} className="w-full aspect-square !rounded-xl" />
                                                <span className={`absolute right-2 top-2 grid h-7 w-7 place-items-center rounded-full text-sm ${on ? 'bg-ink-900 text-white' : 'bg-white/90 text-ink-900 shadow'}`} aria-hidden="true">{on ? '✓' : '+'}</span>
                                            </span>
                                            <span className="mt-2 block text-[13px] leading-snug text-ink-900 line-clamp-2">{p.name}</span>
                                            <span className="mt-0.5 block text-[13px] font-semibold text-ink-900">{p.price_text}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>
            </div>

            {/* The stack, sizes, and the bag */}
            {chosen.length > 0 && (
                <section className="mt-12 rounded-[28px] border border-ink-900/[0.08] bg-white p-5 sm:p-7">
                    <div className="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 className="display text-3xl">Your stack</h2>
                        <p className="text-sm text-ink-700/70">{chosen.length} piece{chosen.length > 1 ? 's' : ''} · <strong className="text-ink-900">{money(total)}</strong></p>
                    </div>
                    <ul className="mt-5 divide-y divide-ink-900/[0.06]">
                        {chosen.map((p) => (
                            <li key={p.id} className="py-3 flex flex-wrap items-center gap-4">
                                <PieceSwatch piece={p} className="w-14 h-14" />
                                <div className="min-w-0 flex-1">
                                    <SmartLink href={p.url} className="block text-sm font-medium text-ink-900 hover:underline underline-offset-4 truncate">{p.name}</SmartLink>
                                    <span className="text-sm text-ink-700/70">{p.price_text}</span>
                                    {p.needs_page && <span className="block text-xs text-ink-700/70">Choose its options on the piece’s own page.</span>}
                                </div>
                                {needsSize(p) && (
                                    <div className="w-full sm:w-auto">
                                        <SizeChips piece={p} value={sizes[p.id]} onChange={(v) => setSizes((s) => ({ ...s, [p.id]: v }))} small />
                                    </div>
                                )}
                                <button type="button" onClick={() => toggle(p)} className="text-xs text-ink-700/70 hover:text-danger-600" aria-label={`Remove ${p.name}`}>Remove</button>
                            </li>
                        ))}
                    </ul>
                    {missing.length > 0 && (
                        <p className="mt-3 text-xs text-ink-700/70">
                            Choose a size for {missing.length === 1 ? 'one piece' : `${missing.length} pieces`} to add {missing.length === chosen.length ? 'them' : 'the whole stack'}. Not sure of your size?{' '}
                            <SmartLink href={missing[0].url} className="underline underline-offset-2">Find it on the piece’s page</SmartLink>.
                        </p>
                    )}
                    <button type="button" onClick={() => addSet(items)} disabled={items.length === 0} className="btn-primary w-full sm:w-auto mt-5 !min-h-12 !px-8 disabled:opacity-50">
                        {items.length === chosen.length ? 'Add the stack to my bag' : `Add ${items.length} of ${chosen.length} to my bag`}
                    </button>
                </section>
            )}

            <MoreTools current="stacker" />
        </div>
    );
}

/* ── The illustrated hand ─────────────────────────────────────────────────── */
function Hand({ skin, stack, byId, colourOf, onRing }) {
    const wrist = stack.filter((s) => s.slot === 'wrist').map((s) => byId[s.id]).filter(Boolean);
    const rings = stack.filter((s) => s.slot !== 'wrist');

    const bangle = (p, i) => {
        const cy = 412 + i * 17;
        const rx = 63 + i * 1.6;
        const ry = 12;
        return { p, cy, rx, ry, thin: p.kind === 'bracelet' };
    };
    const bangles = wrist.map(bangle);

    return (
        <svg viewBox="0 0 400 620" className="block w-full h-auto max-h-[70vh] mx-auto" role="img" aria-label={`An illustrated hand wearing ${stack.length} piece${stack.length === 1 ? '' : 's'}`}>
            <defs>
                {bangles.map(({ p }) => <Metal key={`g${p.id}`} id={`m${p.id}`} c={colourOf(p)} />)}
                {rings.map(({ id }) => byId[id] && <Metal key={`r${id}`} id={`rm${id}`} c={colourOf(byId[id])} vertical />)}
            </defs>

            {/* Back halves of the bangles, behind the arm */}
            {bangles.map(({ p, cy, rx, ry, thin }) => !p.cutout && (
                <path key={`b${p.id}`} d={`M ${200 - rx} ${cy} A ${rx} ${ry} 0 0 1 ${200 + rx} ${cy}`} fill="none" stroke={shade(colourOf(p), -0.35)} strokeWidth={thin ? 4 : 8} strokeLinecap="round" strokeDasharray={thin ? '3 2' : undefined} />
            ))}

            {/* Arm, hand, fingers */}
            <g fill={skin.base} stroke={skin.shadow} strokeWidth="1.5">
                <path d="M 132 620 L 148 430 Q 150 402 158 388 L 242 388 Q 250 402 252 430 L 268 620 Z" />
                {/* thumb: pivots on the palm's edge, so the palm drawn after hides its root */}
                <rect x="110" y="252" width="40" height="124" rx="20" transform="rotate(-30 130 372)" />
                {FINGERS.map((f) => <rect key={f.key} x={f.x - f.w / 2} y={f.top} width={f.w} height={FINGER_BASE + 30 - f.top} rx={f.w / 2} />)}
                <path d="M 156 404 C 124 382 114 310 118 262 L 120 232 Q 124 208 146 206 L 266 206 Q 288 208 290 232 L 290 268 C 294 318 280 382 244 404 Z" />
            </g>
            {/* knuckle and nail hints */}
            <g fill="none" stroke={skin.shadow} strokeWidth="1.2" strokeLinecap="round" opacity="0.7">
                {FINGERS.map((f) => <path key={`n${f.key}`} d={`M ${f.x - f.w / 4} ${f.top + 18} Q ${f.x} ${f.top + 10} ${f.x + f.w / 4} ${f.top + 18}`} />)}
                {FINGERS.map((f) => <path key={`k${f.key}`} d={`M ${f.x - f.w / 5} ${FINGER_BASE - 60} q ${f.w / 5} -4 ${(2 * f.w) / 5} 0`} />)}
            </g>

            {/* Front halves of the bangles, or the cut-out across the wrist */}
            {bangles.map(({ p, cy, rx, ry, thin }) => p.cutout ? (
                <image key={`c${p.id}`} href={p.draw} x={200 - rx - 6} y={cy - 22} width={(rx + 6) * 2} height="44" preserveAspectRatio="xMidYMid meet" />
            ) : (
                <g key={`f${p.id}`}>
                    <path d={`M ${200 - rx} ${cy} A ${rx} ${ry} 0 0 0 ${200 + rx} ${cy}`} fill="none" stroke={`url(#m${p.id})`} strokeWidth={thin ? 4.5 : 9} strokeLinecap="round" strokeDasharray={thin ? '3 2' : undefined} />
                    {!thin && <path d={`M ${200 - rx + 10} ${cy + 4} A ${rx} ${ry} 0 0 0 ${200 + rx - 10} ${cy + 4}`} fill="none" stroke="rgba(255,255,255,0.55)" strokeWidth="1.4" strokeLinecap="round" />}
                </g>
            ))}

            {/* Rings, base of each finger first, a second one above it */}
            {rings.map((s) => {
                const p = byId[s.id];
                const f = FINGERS.find((x) => x.key === s.slot);
                if (!p || !f) return null;
                const level = rings.filter((r) => r.slot === s.slot).findIndex((r) => r.id === s.id);
                const y = FINGER_BASE - 34 - level * 22;
                return (
                    <g key={`ring${s.id}`} onClick={() => onRing(s.id)} style={{ cursor: 'pointer' }}>
                        <title>{`${p.name} — tap to move it to another finger`}</title>
                        {p.cutout ? (
                            <image href={p.draw} x={f.x - f.w / 2 - 12} y={y - 14} width={f.w + 24} height="36" preserveAspectRatio="xMidYMid meet" />
                        ) : (
                            <>
                                <rect x={f.x - f.w / 2 - 3} y={y} width={f.w + 6} height="11" rx="5.5" fill={`url(#rm${s.id})`} stroke={shade(colourOf(p), -0.4)} strokeWidth="0.8" />
                                <circle cx={f.x} cy={y + 1} r="5" fill={shade(colourOf(p), 0.35)} stroke={shade(colourOf(p), -0.3)} strokeWidth="0.8" />
                            </>
                        )}
                    </g>
                );
            })}
        </svg>
    );
}

/** A metallic gradient in the piece's colour. */
function Metal({ id, c, vertical = false }) {
    return (
        <linearGradient id={id} x1="0" y1="0" x2={vertical ? '0' : '1'} y2={vertical ? '1' : '0'}>
            <stop offset="0" stopColor={shade(c, -0.25)} />
            <stop offset="0.35" stopColor={shade(c, 0.45)} />
            <stop offset="0.55" stopColor={shade(c, 0.1)} />
            <stop offset="1" stopColor={shade(c, -0.3)} />
        </linearGradient>
    );
}

Stacker.layout = (page) => <Layout>{page}</Layout>;
