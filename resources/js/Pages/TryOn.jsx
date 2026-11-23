import { useEffect, useState } from 'react';
import Layout from '../Shared/Chrome/Layout';
import SmartLink from '../Shared/SmartLink';
import { useCart } from '../Shared/CartContext';
import { SKIN_TONES } from '../Shared/pieceColour';
import { MoreTools, PageHead, PieceSwatch, SizeChips, cartItem, needsSize, shell } from '../Shared/StyleKit';

/*
 * Earring try-on (Nov 2026; the idea is the Jhumka sample on navira.ink): an
 * illustrated face, hair up or down, four skin tones, and the earring hung
 * from both lobes — the owner's cut-out where there is one, else the piece's
 * photo inside a drop. No camera: nothing to allow, nothing to download, and
 * it works on any phone. It shows colour and how a drop sits against a look;
 * the product photos remain the word on size, and the page says so.
 */

const LOBES = [{ x: 104, y: 236 }, { x: 296, y: 236 }];
const LENGTHS = [['short', 'Short', 0.72], ['medium', 'Medium', 1], ['long', 'Long', 1.35]];
const HAIR = [['down', 'Hair down'], ['up', 'Hair up']];
const HAIR_COLOUR = '#2a1d17';

export default function TryOn({ pieces, start }) {
    const { addSet } = useCart();
    const [current, setCurrent] = useState(() => pieces.find((p) => p.id === start) || pieces[0] || null);
    const [skin, setSkin] = useState(SKIN_TONES[1]);
    const [hair, setHair] = useState('down');
    const [length, setLength] = useState('medium');
    const [size, setSize] = useState(null);

    useEffect(() => setSize(null), [current?.id]);

    const scale = LENGTHS.find(([k]) => k === length)[2];
    const index = current ? pieces.findIndex((p) => p.id === current.id) : -1;
    const step = (d) => pieces.length && setCurrent(pieces[(index + d + pieces.length) % pieces.length]);
    const item = current ? cartItem(current, size) : null;

    return (
        <div className={`${shell} pb-10`}>
            <PageHead
                eyebrow="Try it on"
                title="See them on a face"
                subtitle="Pick a pair and see the colour and the drop against a face — hair up or down. The photos on each piece’s page show its true size."
            />

            {pieces.length === 0 ? (
                <p className="text-sm text-ink-700/70">No earrings in stock right now.</p>
            ) : (
                <div className="grid lg:grid-cols-12 gap-8 lg:gap-12 items-start">
                    <div className="lg:col-span-5 lg:sticky lg:top-28">
                        <div className="relative rounded-[32px] bg-gradient-to-b from-mist-50 to-mist-100 border border-ink-900/[0.06] overflow-hidden">
                            <Face skin={skin} hair={hair} piece={current} scale={scale} />
                            <button type="button" onClick={() => step(-1)} aria-label="Previous pair" className="absolute left-3 top-1/2 -translate-y-1/2 grid h-11 w-11 place-items-center rounded-full bg-white/90 shadow text-lg">‹</button>
                            <button type="button" onClick={() => step(1)} aria-label="Next pair" className="absolute right-3 top-1/2 -translate-y-1/2 grid h-11 w-11 place-items-center rounded-full bg-white/90 shadow text-lg">›</button>
                        </div>

                        <div className="mt-4 grid gap-3">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-2" role="group" aria-label="Skin tone">
                                    {SKIN_TONES.map((t) => (
                                        <button key={t.key} type="button" onClick={() => setSkin(t)} aria-label={t.label} aria-pressed={skin.key === t.key}
                                                className={`h-8 w-8 rounded-full border-2 transition ${skin.key === t.key ? 'border-ink-900 scale-110' : 'border-white shadow'}`}
                                                style={{ background: t.base }} />
                                    ))}
                                </div>
                                <Segmented value={hair} onChange={setHair} options={HAIR} label="Hair" />
                            </div>
                            <Segmented value={length} onChange={setLength} options={LENGTHS} label="Drop length" />
                        </div>

                        {current && (
                            <div className="mt-5 rounded-2xl border border-ink-900/10 bg-white p-4" aria-live="polite">
                                <div className="flex items-center gap-3">
                                    <PieceSwatch piece={current} className="w-14 h-14" />
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-medium text-ink-900 truncate">{current.name}</p>
                                        <p className="text-sm font-semibold text-ink-900">{current.price_text}</p>
                                    </div>
                                    <SmartLink href={current.url} className="text-xs text-ink-700/70 hover:text-ink-900 lux-link shrink-0">View piece</SmartLink>
                                </div>
                                {needsSize(current) && <div className="mt-3"><SizeChips piece={current} value={size} onChange={setSize} small /></div>}
                                {current.needs_page ? (
                                    <SmartLink href={current.url} className="btn-primary w-full mt-3 !min-h-12">Choose options</SmartLink>
                                ) : (
                                    <button type="button" onClick={() => item && addSet([item])} disabled={!item} className="btn-primary w-full mt-3 !min-h-12 disabled:opacity-50">
                                        {item ? 'Add to bag' : 'Choose a size'}
                                    </button>
                                )}
                            </div>
                        )}
                    </div>

                    <div className="lg:col-span-7">
                        <p className="eyebrow mb-4">{pieces.length} pairs to try</p>
                        <ul className="grid grid-cols-3 sm:grid-cols-4 gap-3">
                            {pieces.map((p) => {
                                const on = current?.id === p.id;
                                return (
                                    <li key={p.id}>
                                        <button type="button" onClick={() => { setCurrent(p); if (window.innerWidth < 1024) window.scrollTo({ top: 0, behavior: 'smooth' }); }} aria-pressed={on}
                                                className={`w-full text-left rounded-2xl border p-2 transition-colors duration-300 ${on ? 'border-ink-900 ring-1 ring-ink-900 bg-white' : 'border-ink-900/10 bg-white hover:border-ink-900/50'}`}>
                                            <PieceSwatch piece={p} className="w-full aspect-square !rounded-xl" />
                                            <span className="mt-1.5 block text-[12px] leading-snug text-ink-900 line-clamp-2">{p.name}</span>
                                            <span className="block text-[12px] font-semibold text-ink-900">{p.price_text}</span>
                                        </button>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </div>
            )}

            <MoreTools current="tryOn" />
        </div>
    );
}

function Segmented({ value, onChange, options, label }) {
    return (
        <div className="inline-flex items-center gap-1 rounded-full bg-mist-50 p-1 border border-ink-900/[0.06]" role="group" aria-label={label}>
            {options.map(([key, text]) => (
                <button key={key} type="button" onClick={() => onChange(key)} aria-pressed={value === key}
                        className={`min-h-9 rounded-full px-3.5 text-[12.5px] font-medium transition-colors duration-300 ${value === key ? 'bg-ink-900 text-white' : 'text-ink-800 hover:bg-white'}`}>
                    {text}
                </button>
            ))}
        </div>
    );
}

/* ── The illustrated face ─────────────────────────────────────────────────── */
function Face({ skin, hair, piece, scale }) {
    return (
        <svg viewBox="0 0 400 480" className="block w-full h-auto max-h-[68vh] mx-auto" role="img" aria-label={piece ? `An illustrated face wearing ${piece.name}` : 'An illustrated face'}>
            <defs>
                <linearGradient id="bg-shoulder" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0" stopColor="#efe7dd" /><stop offset="1" stopColor="#e3d7c8" />
                </linearGradient>
            </defs>

            {/* hair, back */}
            {hair === 'down'
                ? <path d="M 92 190 C 84 90 150 46 200 46 C 252 46 318 90 308 190 C 314 260 326 330 344 400 L 56 400 C 74 330 86 260 92 190 Z" fill={HAIR_COLOUR} />
                : <circle cx="200" cy="62" r="40" fill={HAIR_COLOUR} />}

            {/* shoulders and neck */}
            <path d="M 40 480 C 50 410 110 392 160 386 L 240 386 C 290 392 350 410 360 480 Z" fill="url(#bg-shoulder)" />
            <path d="M 166 300 L 166 392 Q 200 410 234 392 L 234 300 Z" fill={skin.base} stroke={skin.shadow} strokeWidth="1.2" />
            <path d="M 166 360 Q 200 384 234 360" fill="none" stroke={skin.shadow} strokeWidth="1" opacity="0.6" />

            {/* ears */}
            {LOBES.map((l, i) => (
                <ellipse key={i} cx={l.x + (i ? -4 : 4)} cy={l.y - 30} rx="14" ry="30" fill={skin.base} stroke={skin.shadow} strokeWidth="1.2" />
            ))}

            {/* head */}
            <path d="M 110 186 C 110 108 150 70 200 70 C 250 70 290 108 290 186 C 290 262 250 318 200 318 C 150 318 110 262 110 186 Z" fill={skin.base} stroke={skin.shadow} strokeWidth="1.4" />

            {/* hair, front */}
            {hair === 'down'
                ? <path d="M 106 176 C 104 104 150 62 200 62 C 252 62 298 104 296 176 C 280 132 246 110 214 106 C 190 124 150 140 106 176 Z" fill={HAIR_COLOUR} />
                : <path d="M 108 168 C 108 100 152 64 200 64 C 248 64 292 100 292 168 C 274 120 240 100 200 100 C 160 100 126 120 108 168 Z" fill={HAIR_COLOUR} />}

            {/* features */}
            <g fill="none" stroke={skin.shadow} strokeLinecap="round">
                <path d="M 148 170 Q 165 160 182 168" strokeWidth="3" stroke={HAIR_COLOUR} />
                <path d="M 218 168 Q 235 160 252 170" strokeWidth="3" stroke={HAIR_COLOUR} />
                <path d="M 152 194 Q 166 186 180 194" strokeWidth="2.4" stroke="#3b2a22" />
                <path d="M 220 194 Q 234 186 248 194" strokeWidth="2.4" stroke="#3b2a22" />
                <path d="M 200 200 L 194 236 Q 200 242 208 238" strokeWidth="1.8" />
            </g>
            <path d="M 178 268 Q 200 282 222 268 Q 200 262 178 268 Z" fill="#b5545c" opacity="0.85" />

            {/* the earrings */}
            {piece && LOBES.map((l, i) => <Earring key={`${piece.id}-${i}`} piece={piece} lobe={l} scale={scale} mirror={i === 1} />)}
        </svg>
    );
}

function Earring({ piece, lobe, scale, mirror }) {
    const w = 34 * scale;
    const h = 64 * scale;
    const id = `drop-${piece.id}-${mirror ? 'r' : 'l'}`;
    const top = lobe.y + 8;
    const flip = mirror ? `translate(${lobe.x * 2} 0) scale(-1 1)` : undefined;

    if (piece.cutout) {
        return (
            <g className="pop-in" transform={flip}>
                <image href={piece.draw} x={lobe.x - w * 0.75} y={lobe.y - 6} width={w * 1.5} height={h * 1.5} preserveAspectRatio="xMidYMin meet" />
            </g>
        );
    }

    // A drop: round at the bottom, pointed at the hook.
    const d = `M ${lobe.x} ${top} C ${lobe.x + w * 0.62} ${top + h * 0.42}, ${lobe.x + w * 0.55} ${top + h}, ${lobe.x} ${top + h} C ${lobe.x - w * 0.55} ${top + h}, ${lobe.x - w * 0.62} ${top + h * 0.42}, ${lobe.x} ${top} Z`;

    return (
        <g className="pop-in" transform={flip}>
            <clipPath id={id}><path d={d} /></clipPath>
            <path d={`M ${lobe.x} ${lobe.y - 4} q 4 6 0 ${top - lobe.y + 4}`} fill="none" stroke="#c9a25c" strokeWidth="1.6" />
            <circle cx={lobe.x} cy={lobe.y - 3} r="2.6" fill="#d8b46a" />
            <image href={piece.draw} x={lobe.x - w} y={top} width={w * 2} height={h} preserveAspectRatio="xMidYMid slice" clipPath={`url(#${id})`} />
            <path d={d} fill="none" stroke="#c9a25c" strokeWidth="1.4" />
        </g>
    );
}

TryOn.layout = (page) => <Layout>{page}</Layout>;
