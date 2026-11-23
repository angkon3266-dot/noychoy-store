import { useEffect, useRef, useState } from 'react';
import SmartLink from './SmartLink';
import { useCart } from './CartContext';
import { money } from './format';
import { PieceSwatch, SizeChips, cartItem, needsSize } from './StyleKit';

/*
 * One "Shop the look" photo (Nov 2026): numbered pins on the pieces in it, a
 * card for the pin you tap, and the whole look into the bag in one go. Used
 * on /looks and by the homepage section. Pins are placed by the owner in
 * Admin → Shop the look, as percentages, so they hold at any size.
 */
export default function LookViewer({ look, compact = false }) {
    const { addSet } = useCart();
    const [open, setOpen] = useState(null);
    const [sizes, setSizes] = useState({});
    const boxRef = useRef(null);

    useEffect(() => {
        if (open === null) return;
        const close = (e) => { if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(null); };
        const esc = (e) => e.key === 'Escape' && setOpen(null);
        document.addEventListener('pointerdown', close);
        document.addEventListener('keydown', esc);
        return () => { document.removeEventListener('pointerdown', close); document.removeEventListener('keydown', esc); };
    }, [open]);

    const pieces = look.hotspots.map((h) => h.piece);
    const items = pieces.map((p) => cartItem(p, sizes[p.id])).filter(Boolean);
    const missing = pieces.filter((p) => p.available && needsSize(p) && !sizes[p.id]).length;
    const total = pieces.reduce((s, p) => s + (Number(p.price) || 0), 0);
    const spot = open !== null ? look.hotspots[open] : null;
    const setSize = (id, v) => setSizes((s) => ({ ...s, [id]: v }));

    return (
        <div ref={boxRef} className={compact ? '' : 'grid lg:grid-cols-12 gap-6 lg:gap-12 items-start'}>
            <div className={compact ? 'relative' : 'relative lg:col-span-7'}>
                <div className="relative overflow-hidden rounded-[28px] bg-mist-100">
                    <img
                        src={look.image}
                        srcSet={look.image450 && look.image450 !== look.image ? `${look.image450} 450w, ${look.image} 1200w` : undefined}
                        sizes={compact ? '(min-width: 1024px) 33vw, 90vw' : '(min-width: 1024px) 55vw, 100vw'}
                        alt={look.title}
                        loading="lazy"
                        decoding="async"
                        className="block w-full h-auto"
                    />
                    {look.hotspots.map((h, i) => (
                        <button
                            key={i}
                            type="button"
                            onClick={() => setOpen(open === i ? null : i)}
                            aria-expanded={open === i}
                            aria-label={`${h.piece.name}, ${h.piece.price_text}`}
                            className="absolute -translate-x-1/2 -translate-y-1/2 grid h-9 w-9 place-items-center"
                            style={{ left: `${h.x}%`, top: `${h.y}%` }}
                        >
                            <span className={`absolute inset-0 rounded-full bg-white/60 ${open === i ? '' : 'animate-ping'}`} aria-hidden="true" />
                            <span className={`relative grid h-7 w-7 place-items-center rounded-full text-[12px] font-semibold shadow-lg ring-2 ring-white transition ${open === i ? 'bg-gold-400 text-ink-900 scale-110' : 'bg-ink-900 text-white'}`}>{i + 1}</span>
                        </button>
                    ))}

                    {spot && (
                        <div
                            className="pop-in absolute z-10 w-[15rem] max-w-[80%] rounded-2xl bg-white p-3 shadow-2xl"
                            style={{
                                left: `${Math.min(Math.max(spot.x, 22), 78)}%`,
                                top: spot.y > 55 ? undefined : `calc(${spot.y}% + 24px)`,
                                bottom: spot.y > 55 ? `calc(${100 - spot.y}% + 24px)` : undefined,
                                transform: 'translateX(-50%)',
                            }}
                        >
                            <PieceCard piece={spot.piece} size={sizes[spot.piece.id]} setSize={(v) => setSize(spot.piece.id, v)} onAdd={(item) => addSet([item])} />
                        </div>
                    )}
                </div>
                {compact && (
                    <div className="mt-3 flex items-center justify-between gap-3">
                        <p className="text-sm text-ink-800 truncate"><span className="font-medium">{look.title}</span> · {pieces.length} piece{pieces.length > 1 ? 's' : ''}</p>
                        <span className="text-xs text-ink-700/60 shrink-0">Tap a number</span>
                    </div>
                )}
            </div>

            {!compact && (
                <div className="lg:col-span-5">
                    <h2 className="display text-3xl sm:text-4xl">{look.title}</h2>
                    {look.caption && <p className="mt-2 text-[15px] leading-relaxed text-ink-700/80">{look.caption}</p>}
                    <ol className="mt-6 divide-y divide-ink-900/[0.06] border-y border-ink-900/[0.06]">
                        {look.hotspots.map((h, i) => (
                            <li key={i} className="py-3 flex flex-wrap items-center gap-3">
                                <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-ink-900 text-[11px] font-semibold text-white">{i + 1}</span>
                                <PieceSwatch piece={h.piece} className="w-12 h-12" />
                                <div className="min-w-0 flex-1">
                                    <SmartLink href={h.piece.url} className="block text-sm font-medium text-ink-900 truncate hover:underline underline-offset-4">{h.piece.name}</SmartLink>
                                    <span className="text-sm text-ink-700/70">{h.piece.available ? h.piece.price_text : 'Sold out'}</span>
                                </div>
                                {h.piece.available && needsSize(h.piece) && (
                                    <div className="w-full pl-9"><SizeChips piece={h.piece} value={sizes[h.piece.id]} onChange={(v) => setSize(h.piece.id, v)} small /></div>
                                )}
                            </li>
                        ))}
                    </ol>
                    {pieces.length > 1 && (
                        <>
                            <button type="button" onClick={() => addSet(items)} disabled={items.length === 0} className="btn-primary w-full mt-5 !min-h-12 disabled:opacity-50">
                                {items.length === pieces.length ? `Add the whole look · ${money(total)}` : `Add ${items.length} of ${pieces.length} to bag`}
                            </button>
                            {missing > 0 && <p className="mt-2 text-xs text-ink-700/70">Choose {missing === 1 ? 'a size' : `${missing} sizes`} above to add every piece.</p>}
                        </>
                    )}
                </div>
            )}
        </div>
    );
}

function PieceCard({ piece, size, setSize, onAdd }) {
    const item = cartItem(piece, size);
    return (
        <div>
            <div className="flex items-center gap-3">
                <PieceSwatch piece={piece} className="w-14 h-14" />
                <div className="min-w-0">
                    <p className="text-[13px] font-medium leading-snug text-ink-900 line-clamp-2">{piece.name}</p>
                    <p className="text-[13px] font-semibold text-ink-900">{piece.available ? piece.price_text : 'Sold out'}</p>
                </div>
            </div>
            {piece.available && needsSize(piece) && <div className="mt-2"><SizeChips piece={piece} value={size} onChange={setSize} small /></div>}
            <div className="mt-3 grid grid-cols-2 gap-2">
                <SmartLink href={piece.url} className="btn-outline !min-h-10 text-[12.5px]">View</SmartLink>
                {piece.needs_page ? (
                    <SmartLink href={piece.url} className="btn-primary !min-h-10 text-[12.5px]">Options</SmartLink>
                ) : (
                    <button type="button" disabled={!item} onClick={() => item && onAdd(item)} className="btn-primary !min-h-10 text-[12.5px] disabled:opacity-50">
                        {piece.available ? (item ? 'Add' : 'Size?') : 'Sold out'}
                    </button>
                )}
            </div>
        </div>
    );
}
