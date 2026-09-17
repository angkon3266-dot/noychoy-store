import { Link, router, usePage } from '@inertiajs/react';
import Icon, { Star } from './Icons';
import { useCart } from './CartContext';
import { money, newEventId } from './format';
import { rewardLabel, t } from './i18n';

// Storefront product card — data shape comes from ProductCardData::make().
export default function ProductCard({ product: p }) {
    const { add, gift } = useCart();
    const { props } = usePage();
    const lang = props.chrome?.lang || 'en';

    const soldOut = !p.available && !p.preorder;
    const ladder = soldOut ? null : ladderHint(gift, p, lang);

    return (
        <div className="group relative block">
            <Link href={p.url} className="block">
                <div className="aspect-square overflow-hidden rounded-xl bg-gold-100 relative">
                    {p.thumb ? (
                        <img
                            src={p.thumb}
                            alt={p.name}
                            loading="lazy"
                            width="450"
                            height="450"
                            {...(p.srcset
                                ? { srcSet: p.srcset, sizes: '(min-width: 768px) 25vw, 50vw' }
                                : (p.thumb450 ? { srcSet: `${p.thumb450} 450w, ${p.thumb} 1200w`, sizes: '(min-width: 768px) 25vw, 50vw' } : {}))}
                            className="h-full w-full object-cover transition duration-500 group-hover:scale-105"
                        />
                    ) : (
                        <div className="flex h-full items-center justify-center text-gold-300">
                            <svg aria-hidden="true" className="w-12 h-12" fill="none" stroke="currentColor" strokeWidth="1" viewBox="0 0 24 24"><path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909M4.5 19.5h15a.75.75 0 00.75-.75V5.25a.75.75 0 00-.75-.75h-15a.75.75 0 00-.75.75v13.5c0 .414.336.75.75.75z" /></svg>
                        </div>
                    )}
                    {p.preorder ? (
                        <span className="absolute top-2 left-2 badge bg-promo-600 text-white">Pre-order</span>
                    ) : p.on_sale ? (
                        <span className="absolute top-2 left-2 badge bg-danger-600 text-white">-{p.discount_percent}%</span>
                    ) : null}
                    {soldOut && <span className="absolute top-2 right-2 badge bg-ink-900/80 text-white">Sold out</span>}
                </div>
                <h3 className="mt-3 text-sm font-medium text-ink-800 line-clamp-2 group-hover:text-gold-700">{p.name}</h3>
                {p.rating != null && (
                    <div className="mt-1 flex items-center gap-1 text-xs">
                        <span className="flex text-gold-500">
                            {[1, 2, 3, 4, 5].map((i) => <Star key={i} className="w-3.5 h-3.5" off={i > Math.round(p.rating)} />)}
                        </span>
                        <span className="text-ink-700/70">({p.review_count})</span>
                    </div>
                )}
                <div className="mt-1 flex items-center gap-2 flex-wrap">
                    {p.member ? (
                        <>
                            <span className="font-semibold text-gold-700">{p.has_variants ? 'From ' : ''}{p.member.price_text}</span>
                            <span className="text-xs text-ink-500 line-through">{p.price_text}</span>
                            <span className="badge bg-gold-600 text-white text-[10px]">Member −{p.member.pct}%</span>
                        </>
                    ) : (
                        <>
                            <span className="font-semibold text-gold-700">{p.has_variants ? 'From ' : ''}{p.price_text}</span>
                            {p.on_sale && <span className="text-xs text-ink-500 line-through">{p.compare_text}</span>}
                        </>
                    )}
                </div>
                {ladder && (
                    <p className="mt-0.5 flex min-w-0 items-center gap-1 text-[11px] font-medium leading-tight text-gold-800" lang={lang}>
                        <Icon name="gift" className="w-3 h-3 shrink-0 text-gold-700" strokeWidth={2} />
                        <span className="min-w-0 truncate">{ladder}</span>
                    </p>
                )}
            </Link>

            {/* Quick actions: always visible on touch, reveal on hover on desktop. */}
            <div className="mt-2 transition duration-200 ease-out md:opacity-0 md:translate-y-1 md:pointer-events-none md:group-hover:opacity-100 md:group-hover:translate-y-0 md:group-hover:pointer-events-auto">
                {soldOut ? (
                    <button type="button" disabled className="w-full rounded-full border border-ink-100 bg-ink-50 px-3 py-2 text-xs font-medium text-ink-400 cursor-not-allowed">Sold out</button>
                ) : p.has_variants ? (
                    <Link href={p.url} className="flex w-full items-center justify-center gap-1.5 rounded-full border border-ink-200 px-3 py-2 text-xs font-medium text-ink-800 hover:border-gold-400 hover:text-gold-700 transition">
                        <svg aria-hidden="true" className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M10.5 6h9.75M10.5 12h9.75m-9.75 6h9.75M3.75 6h.007v.008H3.75V6zm.375 6a.375.375 0 11-.75 0 .375.375 0 01.75 0zm-.375 6h.007v.008H3.75V18z" /></svg>
                        Select options
                    </Link>
                ) : (
                    <div className="flex gap-1.5">
                        <button
                            type="button"
                            onClick={() => add(p.add_url, { variant_id: '', qty: 1 }, {
                                contentId: `prod-${p.id}`, name: p.name, value: p.price,
                            })}
                            className="flex flex-1 items-center justify-center gap-1.5 rounded-full border border-ink-200 px-3 py-2 text-xs font-medium text-ink-800 hover:border-gold-400 hover:text-gold-700 transition"
                            title="Add to cart"
                            aria-label="Add to cart"
                        >
                            <Icon name="cart" className="w-4 h-4" strokeWidth={1.8} />
                            <span className="hidden sm:inline">Add</span>
                        </button>
                        {/* Buy now posts through Inertia: the server adds to the
                            cart and redirects to /checkout, which renders in place. */}
                        <button
                            type="button"
                            onClick={() => {
                                // Buy now skips the cart, so without this the
                                // strongest intent signal on the page reached
                                // Meta as nothing at all.
                                const eventId = newEventId('AddToCart');
                                if (window.track) {
                                    window.track('AddToCart', {
                                        content_ids: [`prod-${p.id}`],
                                        content_name: p.name,
                                        content_type: 'product',
                                        value: p.price,
                                        currency: 'BDT',
                                    }, { eventID: eventId });
                                }
                                router.post(p.buynow_url, { variant_id: '', qty: 1, event_id: eventId });
                            }}
                            className="flex-1 rounded-full bg-gold-700 px-3 py-2 text-xs font-medium text-white hover:bg-gold-800 transition"
                        >
                            {p.preorder ? 'Book now' : 'Buy now'}
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * What the next piece in the cart would earn on the ladder, for this card:
 * "৳50 off → ৳1,400", "2% off", "+ Free delivery", "+ Free gift".
 *
 * The owner's call on 2026-09-17: the ladder was told only in the cart, so the
 * first ৳50 off reached nobody browsing the shop. Every card now names the
 * reward the next piece opens. It is worked out here, in the browser, from
 * useCart().gift (the shared ladder progress, refreshed by every add and
 * remove) — never from ProductCardData, whose payloads are cached and shared
 * between visitors (DailyDeals, the assistant), and a cart-shaped number in
 * them would be another shopper's.
 *
 * Only rungs that open at exactly gift.units + 1 count: a rung two pieces away
 * is not what this piece earns. Values, thresholds and types all come from the
 * rungs in Admin → Offers. A flat rung comes off the price the card shows —
 * the member price when there is one — clamped at ৳0. A percent rung applies
 * to the whole cart, not this piece, so it is named rather than priced. A gift
 * rung is only promised when a gift can really be handed out (`gift.available`:
 * a gifts collection with something published in it), the same honesty the
 * server keeps when it quotes the ladder. A collection that is set but empty
 * still has a name and a link, and the card used to promise "+ Free gift" on
 * the strength of that alone.
 */
function ladderHint(gift, p, lang) {
    if (!gift?.tiers?.length) return null;

    const next = Number(gift.units || 0) + 1;
    const rungs = gift.tiers.filter((tier) => Number(tier.threshold) === next);
    if (!rungs.length) return null;

    const sum = (type) => rungs
        .filter((tier) => tier.type === type)
        .reduce((total, tier) => total + (Number(tier.value) || 0), 0);

    const parts = [];

    const flat = sum('flat');
    if (flat > 0) {
        // price_text is what the card prints (whole taka), so the arrow lands
        // on a number the shopper can check against it.
        const shown = (p.member && Number(String(p.member.price_text).replace(/\D/g, ''))) || Number(p.price) || 0;
        parts.push(t(lang, p.has_variants ? 'card.flat.from' : 'card.flat', {
            off: rewardLabel(lang, `${money(flat)} off`),
            price: money(Math.max(0, shown - flat)),
        }));
    }

    const percent = Math.round(sum('percent') * 100) / 100;
    if (percent > 0) {
        parts.push(rewardLabel(lang, `${percent}% off`));
    }

    if (rungs.some((tier) => tier.type === 'free_delivery')) {
        parts.push(`+ ${rewardLabel(lang, 'Free delivery')}`);
    }

    if (rungs.some((tier) => tier.type === 'free_gift') && gift.gift?.available) {
        parts.push(`+ ${rewardLabel(lang, 'Free gift')}`);
    }

    if (!parts.length) return null;

    // "৳50 off → ৳1,400 + 2% off": everything after the first reward joins
    // with a plus, and the switches already carry theirs.
    return parts.map((part, i) => (i === 0 || part.startsWith('+') ? part : `+ ${part}`)).join(' ');
}
