import { useEffect, useRef } from 'react';
import Icon from './Icons';
import { ordinal, rewardLabel, t } from './i18n';

// The reward ladder as one row of chips under Add to cart / Buy now — "1st ৳50
// · 2nd ৳60 · 3rd ৳70 …" — so the shopper sees that every piece earns more
// before she has added the first (owner, 2026-09-17; until then the ladder was
// told only in the cart and the mini-cart).
//
// It reads useCart().gift, the same progress the mini-cart shows, so it moves
// the moment an add or a remove comes back, on any page, without asking the
// server again. Rungs, thresholds and rewards all come from Admin → Offers:
// nothing here knows how many there are or what they give. Unlocked rungs are
// ticked, the next one is ringed.
//
// Kept to a single line so the buy box the owner de-cluttered on 2026-09-10
// grows by one row, not a panel. A tall ladder scrolls sideways on a phone
// instead of wrapping — and the scroller has no intrinsic width of its own
// (w-0 min-w-full), so a long row can never widen the page.
export default function LadderRow({ gift, lang = 'en' }) {
    const scroller = useRef(null);
    const nextN = gift?.next?.n ?? null;

    // Bring the next rung into view when the ladder has climbed past the
    // phone's width. scrollLeft on the row itself, not scrollIntoView, which
    // would also scroll the page to the chip.
    useEffect(() => {
        const box = scroller.current;
        const chip = box?.querySelector('[data-next]');
        if (!box || !chip || box.scrollWidth <= box.clientWidth) return;
        box.scrollLeft = Math.max(0, chip.offsetLeft - 24);
    }, [nextN]);

    if (!gift?.tiers?.length) return null;

    const heading = t(lang, 'ladder.row.heading');

    return (
        <div className="mt-4" lang={lang}>
            <p className="flex items-center gap-1.5 text-xs font-semibold text-ink-800">
                <Icon name="gift" className="w-3.5 h-3.5 shrink-0 text-gold-700" strokeWidth={2} />
                {heading}
            </p>
            <div
                ref={scroller}
                className="relative mt-1.5 w-0 min-w-full overflow-x-auto overscroll-x-contain"
                style={{ scrollbarWidth: 'none', msOverflowStyle: 'none', WebkitOverflowScrolling: 'touch' }}
            >
                {/* Padded so the next rung's ring is not clipped by the scroller. */}
                <ol aria-label={heading} className="flex w-max gap-1.5 px-0.5 py-1">
                    {gift.tiers.map((tier) => {
                        const isNext = !tier.unlocked && nextN === tier.n;
                        // Money and percent rungs read best as their figure
                        // ("৳50", "2%"); the two switches need their name.
                        const reward = tier.type === 'flat' || tier.type === 'percent'
                            ? tier.short
                            : rewardLabel(lang, tier.label);
                        const tone = tier.unlocked
                            ? 'border-success-200 bg-success-50 text-success-700'
                            : isNext
                                ? 'border-gold-500 bg-gold-50 text-gold-800 ring-2 ring-gold-200'
                                : 'border-ink-100 bg-white text-ink-700';

                        return (
                            <li
                                key={tier.n}
                                {...(isNext ? { 'data-next': '', 'aria-current': 'step' } : {})}
                                className={`inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full border px-2.5 py-1 text-xs ${tone}`}
                            >
                                {tier.unlocked && (
                                    <>
                                        <Icon name="check" className="w-3 h-3 shrink-0" strokeWidth={2.5} />
                                        <span className="sr-only">{t(lang, 'ladder.row.unlocked')}: </span>
                                    </>
                                )}
                                <span className={tier.unlocked ? '' : 'text-ink-500'}>{ordinal(lang, tier.threshold)}</span>
                                <span className="font-semibold">{reward}</span>
                            </li>
                        );
                    })}
                </ol>
            </div>
        </div>
    );
}
