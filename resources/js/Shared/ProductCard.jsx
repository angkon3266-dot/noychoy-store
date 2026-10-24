import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Icon, { Star } from './Icons';
import { useCart } from './CartContext';
import { money, newEventId } from './format';
import { rewardLabel, t } from './i18n';
import OfferCountdown from './OfferCountdown';

// Storefront product card — data shape comes from ProductCardData::make().
export default function ProductCard({ product: p }) {
    const { add, gift } = useCart();
    const { props } = usePage();
    const lang = props.chrome?.lang || 'en';
    // One Buy now at a time: a double tap sent two posts and two Pixel
    // AddToCarts. The server keeps a repeat to one piece anyway
    // (CartService::ensure), so this only spares the second request.
    const [buying, setBuying] = useState(false);

    const soldOut = !p.available && !p.preorder;
    const ladder = soldOut ? null : ladderHint(gift, p, lang);

    const buyNow = () => {
        if (buying) return;
        setBuying(true);
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
        // Buy now posts through Inertia: the server adds to the
        // cart and redirects to /checkout, which renders in place.
        router.post(p.buynow_url, { variant_id: '', qty: 1, event_id: eventId }, {
            onFinish: () => setBuying(false),
        });
    };
    const quickAdd = () => add(p.add_url, { variant_id: '', qty: 1 }, {
        contentId: `prod-${p.id}`, name: p.name, value: p.price,
    });

    // The two actions, laid out for either place they appear: a glass bar
    // that rises over the photo on desktop hover, and a row under the price on
    // touch screens (where there is no hover to reveal it).
    const actions = (onImage) => {
        const outline = onImage
            ? 'bg-white/85 text-ink-900 hover:bg-white backdrop-blur'
            : 'border border-ink-900/15 text-ink-900 hover:border-ink-900';
        const solid = 'bg-ink-900 text-white hover:bg-ink-800';
        const btn = 'flex items-center justify-center gap-1.5 rounded-full h-10 px-3 text-[12px] font-medium tracking-[0.04em] transition-colors duration-300';

        if (soldOut) {
            return <button type="button" disabled className={`${btn} w-full ${onImage ? 'bg-white/80 text-ink-500' : 'border border-ink-900/10 text-ink-400'} cursor-not-allowed`}>Sold out</button>;
        }
        if (p.has_variants) {
            return (
                <Link href={p.url} className={`${btn} w-full ${onImage ? solid : outline}`}>
                    Select options
                    <Icon name="chevronRight" className="w-3.5 h-3.5" strokeWidth={2} />
                </Link>
            );
        }
        return (
            <div className="flex gap-1.5">
                <button type="button" onClick={quickAdd} className={`${btn} ${onImage ? 'w-10 shrink-0 px-0' : 'flex-1'} ${outline}`} title="Add to cart" aria-label="Add to cart">
                    <Icon name="bag" className="w-4 h-4" strokeWidth={1.7} />
                    {!onImage && <span>Add</span>}
                </button>
                <button type="button" disabled={buying} onClick={buyNow} className={`${btn} flex-1 ${solid} disabled:opacity-60`}>
                    {p.preorder ? 'Book now' : 'Buy now'}
                </button>
            </div>
        );
    };

    return (
        <div className="group relative block">
            <div className="relative">
                <Link href={p.url} className="block" tabIndex={-1} aria-hidden="true">
                    <div className="lux-card-media relative aspect-[4/5] overflow-hidden rounded-[18px] bg-mist-100">
                        {p.thumb ? (
                            <>
                                <img
                                    src={p.thumb}
                                    alt=""
                                    loading="lazy"
                                    decoding="async"
                                    width="450"
                                    height="563"
                                    {...(p.srcset
                                        ? { srcSet: p.srcset, sizes: '(min-width: 1024px) 24vw, (min-width: 768px) 30vw, 50vw' }
                                        : (p.thumb450 ? { srcSet: `${p.thumb450} 450w, ${p.thumb} 1200w`, sizes: '(min-width: 768px) 25vw, 50vw' } : {}))}
                                    className={`absolute inset-0 h-full w-full object-cover transition duration-[900ms] ease-[var(--ease-lux)] group-hover:scale-[1.04] ${p.hover ? 'md:group-hover:opacity-0' : ''}`}
                                />
                                {p.hover && (
                                    <img
                                        src={p.hover}
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        className="absolute inset-0 hidden md:block h-full w-full object-cover opacity-0 scale-[1.04] transition duration-[900ms] ease-[var(--ease-lux)] group-hover:opacity-100 group-hover:scale-100"
                                    />
                                )}
                            </>
                        ) : (
                            <div className="flex h-full items-center justify-center text-ink-300">
                                <Icon name="diamond" className="w-10 h-10" strokeWidth={1} />
                            </div>
                        )}
                    </div>
                </Link>

                <div className="pointer-events-none absolute top-2.5 left-2.5 right-2.5 flex items-start justify-between gap-2">
                    {p.preorder ? (
                        <span className="rounded-full bg-white/90 px-2.5 py-1 text-[10.5px] font-semibold tracking-[0.06em] uppercase text-promo-700 backdrop-blur">Pre-order</span>
                    ) : p.on_sale ? (
                        <span className="rounded-full bg-gold-500 px-2.5 py-1 text-[11px] font-semibold text-ink-900">−{p.discount_percent}%</span>
                    ) : <span />}
                    {soldOut && <span className="rounded-full bg-ink-900/85 px-2.5 py-1 text-[10.5px] font-medium tracking-[0.06em] uppercase text-white">Sold out</span>}
                </div>

                {/* Desktop: actions rise over the photo on hover / keyboard focus. */}
                <div className="hidden md:block absolute inset-x-2.5 bottom-2.5 translate-y-2 opacity-0 pointer-events-none transition duration-500 ease-[var(--ease-lux)] group-hover:translate-y-0 group-hover:opacity-100 group-hover:pointer-events-auto group-focus-within:translate-y-0 group-focus-within:opacity-100 group-focus-within:pointer-events-auto">
                    {actions(true)}
                </div>
            </div>

            <Link href={p.url} className="block pt-3.5">
                <h3 className="font-sans text-[13.5px] leading-snug text-ink-800 line-clamp-2 transition-colors group-hover:text-ink-900">{p.name}</h3>
                {p.rating != null && (
                    <div className="mt-1.5 flex items-center gap-1 text-[11px] text-ink-500" aria-label={`Rated ${p.rating} out of 5 from ${p.review_count} reviews`}>
                        <Star className="w-3 h-3 text-gold-500" />
                        <span className="font-medium text-ink-800">{Number(p.rating).toFixed(1)}</span>
                        <span>({p.review_count})</span>
                    </div>
                )}
                <div className="mt-1.5 flex items-baseline gap-2 flex-wrap">
                    {p.member ? (
                        <>
                            <span className="text-[15px] font-semibold tracking-[-0.01em] text-ink-900">{p.has_variants ? 'From ' : ''}{p.member.price_text}</span>
                            <span className="text-xs text-ink-500 line-through">{p.price_text}</span>
                            <span className="rounded-full bg-ink-900 px-2 py-0.5 text-[10px] font-medium text-white">Member −{p.member.pct}%</span>
                        </>
                    ) : (
                        <>
                            <span className={`text-[15px] font-semibold tracking-[-0.01em] ${p.on_sale ? 'text-danger-600' : 'text-ink-900'}`}>{p.has_variants ? 'From ' : ''}{p.price_text}</span>
                            {p.on_sale && <span className="text-xs text-ink-500 line-through">{p.compare_text}</span>}
                        </>
                    )}
                </div>
                {p.offer_ends && (
                    <p className="mt-1 flex items-center gap-1 text-[11px] font-medium leading-tight text-danger-600">
                        <Icon name="clock" className="w-3 h-3 shrink-0" strokeWidth={2} />
                        <OfferCountdown ends={p.offer_ends} lang={lang} compact />
                    </p>
                )}
                {ladder && (
                    <p className="mt-1 flex min-w-0 items-center gap-1 text-[11px] font-medium leading-tight text-ink-700" lang={lang}>
                        <Icon name="gift" className="w-3 h-3 shrink-0 text-gold-600" strokeWidth={2} />
                        <span className="min-w-0 truncate">{ladder}</span>
                    </p>
                )}
            </Link>

            {/* Touch screens: always visible, under the price. */}
            <div className="mt-2.5 md:hidden">
                {actions(false)}
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
