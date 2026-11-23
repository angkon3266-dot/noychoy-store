import { usePage } from '@inertiajs/react';
import SmartLink from './SmartLink';
import Icon from './Icons';

/*
 * Shared bits of the style tools (Nov 2026): the stacker, the try-on, Shop the
 * look, the gift quiz and the wishlist. A "piece" is the payload from
 * App\Support\Storefront\StylePieces::make().
 */

export const shell = 'mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-10';

export function PageHead({ eyebrow, title, subtitle, children }) {
    return (
        <div className="pt-8 lg:pt-14 pb-6 lg:pb-10">
            {eyebrow && <p className="eyebrow flex items-center gap-3"><span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />{eyebrow}</p>}
            <h1 className="display mt-4 text-[2.4rem] sm:text-6xl lg:text-[4.2rem] leading-[1.05]">{title}</h1>
            {subtitle && <p className="mt-4 max-w-2xl text-[15px] leading-relaxed text-ink-700/80">{subtitle}</p>}
            {children}
        </div>
    );
}

/** The value a piece will be bought in: its chosen size, or none needed. */
export function needsSize(piece) {
    return !!piece.sizes && piece.sizes.values.length > 0;
}

/** The cart line for a piece (CartContext.addSet), or null while a size is missing. */
export function cartItem(piece, sizeValue) {
    if (!piece.available || piece.needs_page) return null;
    if (needsSize(piece)) {
        const v = piece.sizes.values.find((s) => s.value === sizeValue && s.in_stock);
        if (!v) return null;
        return { id: piece.id, variantId: v.variant_id, price: v.price, contentId: `prod-${piece.id}-var-${v.variant_id}` };
    }
    return { id: piece.id, price: piece.price, contentId: piece.content_id };
}

/** A compact size chooser for one piece. */
export function SizeChips({ piece, value, onChange, small = false }) {
    if (!needsSize(piece)) return null;
    return (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={`${piece.sizes.name} for ${piece.name}`}>
            {piece.sizes.values.map((s) => (
                <button
                    key={s.value}
                    type="button"
                    disabled={!s.in_stock}
                    onClick={() => onChange(value === s.value ? null : s.value)}
                    aria-pressed={value === s.value}
                    className={`rounded-full border ${small ? 'min-h-8 px-3 text-xs' : 'min-h-10 px-4 text-sm'} transition-colors duration-300 ${value === s.value ? 'border-ink-900 bg-ink-900 text-white' : 'border-ink-900/15 text-ink-900 hover:border-ink-900'} ${!s.in_stock ? 'opacity-40 line-through cursor-not-allowed' : ''}`}
                >
                    {s.value}
                </button>
            ))}
        </div>
    );
}

const TOOLS = [
    ['giftQuiz', '/gift-finder', 'Gift quiz', 'gift'],
    ['stacker', '/stack', 'Stack bangles & rings', 'sparkle'],
    ['tryOn', '/try-on', 'Try on earrings', 'diamond'],
    ['looks', '/looks', 'Shop the look', 'heart'],
];

/** "More ways to shop" — every tool but the one you are on. */
export function MoreTools({ current }) {
    const { props } = usePage();
    const urls = props.chrome?.urls || {};
    const others = TOOLS.filter(([key]) => key !== current);

    return (
        <section className="mt-16 lg:mt-24 border-t border-ink-900/[0.08] pt-10">
            <p className="eyebrow mb-5">More ways to find your piece</p>
            <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                {others.map(([key, path, label, icon]) => (
                    <SmartLink key={key} href={urls[key] || path} className="group flex items-center justify-between gap-4 rounded-2xl border border-ink-900/10 bg-white px-5 py-4 hover:border-ink-900 transition-colors duration-300">
                        <span className="flex items-center gap-3 text-[15px] font-medium text-ink-900">
                            <span className="grid h-10 w-10 place-items-center rounded-full bg-mist-50 border border-ink-900/[0.06]"><Icon name={icon} className="w-[18px] h-[18px]" /></span>
                            {label}
                        </span>
                        <span aria-hidden="true" className="text-ink-700/50 transition-transform duration-300 group-hover:translate-x-1">→</span>
                    </SmartLink>
                ))}
            </div>
        </section>
    );
}

/**
 * A round swatch of the piece: the cut-out on a soft ground when there is
 * one, else the photo filling the circle.
 */
export function PieceSwatch({ piece, className = 'w-16 h-16' }) {
    return (
        <span className={`relative block shrink-0 overflow-hidden rounded-full bg-mist-100 ${className}`}>
            {piece.draw && (
                <img
                    src={piece.cutout ? piece.draw : (piece.thumb || piece.draw)}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className={`absolute inset-0 h-full w-full ${piece.cutout ? 'object-contain p-1.5' : 'object-cover'}`}
                />
            )}
        </span>
    );
}
