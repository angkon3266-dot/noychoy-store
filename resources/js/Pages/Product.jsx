import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import ShareButton from '../Shared/ShareButton';
import Icon, { Star, WhatsApp } from '../Shared/Icons';
import { useCart } from '../Shared/CartContext';
import { csrf, fetchJson, money } from '../Shared/format';

// The React product page — a superset of the Blade "showcase" template:
// gallery + zoom + videos, conversion buy box (variants, qty tiers, offers),
// story sections, reviews, FBT bundle, related & recently viewed.
export default function Product(props) {
    const { product, pp, vcEventId } = props;

    // ViewContent Pixel event, deduplicated against the server CAPI call via
    // the shared event id — fires again on every SPA product navigation.
    useEffect(() => {
        if (window.track) {
            window.track('ViewContent', {
                content_ids: [product.meta_content_id],
                content_name: product.name,
                content_type: 'product',
                value: product.price,
                currency: 'BDT',
            }, { eventID: vcEventId });
        }
    }, [product.id, vcEventId]);

    const purchase = usePurchase(pp);

    return (
        <div className="mx-auto max-w-7xl px-4 py-8">
            <Breadcrumb product={product} />

            <div className="grid lg:grid-cols-2 gap-10">
                <Gallery product={product} img={purchase.img} setImg={purchase.setImg} />
                <BuyBox {...props} purchase={purchase} />
            </div>

            <StorySections sections={product.sections} />
            <Description text={product.description} />
            <Specs specs={product.specs} />
            <Reviews {...props} />
            <FrequentlyBought fbt={props.fbt} />
            <CardStrip title="You may also like" products={props.related} cols="grid-cols-2 md:grid-cols-3 lg:grid-cols-5" />
            <CardStrip title="Recently viewed" products={props.recentlyViewed} cols="grid-cols-2 md:grid-cols-4" />
            <StickyBar {...props} purchase={purchase} />
        </div>
    );
}

Product.layout = (page) => <Layout>{page}</Layout>;

/* ── Purchase state: the React port of Alpine's productPage() component ───── */
function usePurchase(pp) {
    const [img, setImg] = useState(pp.image || '');
    const [qty, setQty] = useState(1);
    const [selected, setSelected] = useState(() => {
        const init = {};
        for (const a of pp.attributes || []) {
            if ((a.values || []).length === 1) init[a.name] = a.values[0];
        }
        return init;
    });

    const attributes = pp.attributes || [];
    const variants = pp.variants || [];
    const offers = (pp.offers || []).map((o) => ({ min_qty: Number(o.min_qty), percent: Number(o.percent) }));

    const matched = useMemo(() => {
        if (!pp.hasVariants) return null;
        if (attributes.some((a) => !selected[a.name])) return null;
        return variants.find((v) => attributes.every((a) => String(v.attrs[a.name]) === String(selected[a.name]))) || null;
    }, [pp.hasVariants, attributes, variants, selected]);

    const variantId = pp.hasVariants ? (matched ? String(matched.id) : '') : 'none';
    const unitPrice = pp.hasVariants ? (matched ? matched.price : pp.price) : pp.price;
    const compareAt = pp.hasVariants ? (matched ? (matched.compare || 0) : 0) : (pp.compare || 0);
    const onSale = compareAt > unitPrice;
    const discountPct = onSale ? Math.round((1 - unitPrice / compareAt) * 100) : 0;

    const offerPercent = offers.reduce((best, o) => (qty >= o.min_qty && o.percent > best ? o.percent : best), 0);
    const price = offerPercent > 0 ? Math.round(unitPrice * (1 - offerPercent / 100) * 100) / 100 : unitPrice;
    const savings = Math.round((unitPrice - price) * qty * 100) / 100;
    const canBuy = !pp.hasVariants || (!!matched && matched.stock > 0);

    const selectAttr = (name, val) => {
        setSelected((prev) => {
            const next = { ...prev, [name]: val };
            const m = variants.find((v) => attributes.every((a) => String(v.attrs[a.name]) === String(next[a.name])));
            if (m && m.image) setImg(m.image);
            return next;
        });
    };

    const valueInStock = (name, val) =>
        variants.some((v) => String(v.attrs[name]) === String(val) && v.stock > 0);

    const priceText = () => {
        if (pp.hasVariants && !matched) {
            const prices = variants.map((v) => v.price).filter((p) => p > 0);
            return prices.length ? 'From ' + money(Math.min(...prices)) : money(pp.price);
        }
        return money(price);
    };

    /** One event id shared by the browser Pixel and the server CAPI AddToCart. */
    const makeAddEvent = useCallback(() => {
        const cid = (pp.hasVariants && matched)
            ? `prod-${pp.id}-var-${matched.id}`
            : `prod-${pp.id}`;
        const eventId = 'AddToCart.' + ((self.crypto && crypto.randomUUID)
            ? crypto.randomUUID()
            : (Date.now() + '-' + Math.random().toString(16).slice(2)));
        if (window.track) {
            window.track('AddToCart', {
                content_ids: [cid],
                content_name: pp.name,
                content_type: 'product',
                value: price * qty,
                currency: 'BDT',
            }, { eventID: eventId });
        }
        return eventId;
    }, [pp, matched, price, qty]);

    return {
        img, setImg, qty, setQty, selected, selectAttr, valueInStock,
        matched, variantId, unitPrice, compareAt, onSale, discountPct,
        offerPercent, price, savings, canBuy, priceText, makeAddEvent,
        hasVariants: !!pp.hasVariants,
        attributesList: attributes,
        variantStock: matched ? matched.stock : null,
    };
}

function Breadcrumb({ product }) {
    const { props } = usePage();
    const home = props.chrome?.urls?.home || '/';

    return (
        <nav aria-label="Breadcrumb" className="text-sm text-ink-700/60 mb-6">
            <a href={home} className="hover:text-gold-700">Home</a>{' / '}
            {product.category && (
                <>
                    <Link href={product.category.url} className="hover:text-gold-700">{product.category.name}</Link>{' / '}
                </>
            )}
            <span className="text-ink-800">{product.name}</span>
        </nav>
    );
}

/* ── Gallery with zoom lightbox and video thumbs ──────────────────────────── */
function Gallery({ product, img, setImg }) {
    const [zoom, setZoom] = useState(false);
    const [video, setVideo] = useState(null);   // {embed} | {src} | null

    useEffect(() => {
        const esc = (e) => e.key === 'Escape' && setZoom(false);
        window.addEventListener('keydown', esc);
        return () => window.removeEventListener('keydown', esc);
    }, []);

    const current = img || product.images[0]?.url || '';
    const currentImage = product.images.find((im) => im.url === current) || product.images[0];

    return (
        <div>
            <div className="aspect-square overflow-hidden rounded-2xl bg-gold-100 group relative">
                {video?.embed && (
                    <iframe src={`${video.embed}?autoplay=1`} className="absolute inset-0 h-full w-full" allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen />
                )}
                {video?.src && (
                    <video src={video.src} controls autoPlay playsInline className="absolute inset-0 h-full w-full object-contain bg-black" />
                )}
                {!video && (
                    product.images.length ? (
                        <>
                            <img
                                src={current}
                                alt={currentImage?.alt || product.name}
                                {...(currentImage?.thumb ? { srcSet: `${currentImage.thumb} 450w, ${current} 1200w`, sizes: '(min-width: 1024px) 50vw, 100vw' } : {})}
                                fetchPriority="high"
                                decoding="async"
                                className="h-full w-full object-cover cursor-zoom-in transition duration-500 group-hover:scale-105"
                                onClick={() => setZoom(true)}
                            />
                            <span className="absolute bottom-3 right-3 rounded-full bg-white/80 p-2 text-ink-700 opacity-0 group-hover:opacity-100 transition pointer-events-none">
                                <Icon name="zoomIn" className="w-4 h-4" />
                            </span>
                        </>
                    ) : (
                        <div className="flex h-full items-center justify-center text-gold-300">No image</div>
                    )
                )}
                {video && (
                    <button type="button" onClick={() => setVideo(null)} className="absolute top-3 right-3 z-10 grid h-8 w-8 place-items-center rounded-full bg-white/85 text-ink-900 shadow hover:bg-white" title="Back to photos" aria-label="Back to photos">
                        <Icon name="close" className="w-4 h-4" strokeWidth={2} />
                    </button>
                )}
            </div>

            {zoom && (
                <div className="fixed inset-0 z-[80] bg-black/80 flex items-center justify-center p-4" onClick={() => setZoom(false)}>
                    <img src={current} alt={product.name} className="max-h-[90vh] max-w-[90vw] rounded-lg object-contain" />
                    <button className="absolute top-4 right-4 text-white text-3xl leading-none">×</button>
                </div>
            )}

            {(product.images.length > 1 || product.videos.length > 0) && (
                <div className="mt-4 grid grid-cols-5 gap-3">
                    {product.images.map((image) => (
                        <button
                            key={image.id}
                            onClick={() => { setImg(image.url); setVideo(null); }}
                            aria-label={`View photo ${product.images.indexOf(image) + 1}`}
                            className={`aspect-square overflow-hidden rounded-lg bg-gold-100 ring-2 ${current === image.url && !video ? 'ring-gold-500' : 'ring-transparent'}`}
                        >
                            <img src={image.thumb || image.url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />
                        </button>
                    ))}
                    {product.videos.map((v, i) => (
                        <button
                            key={i}
                            type="button"
                            onClick={() => setVideo(v.embed ? { embed: v.embed } : { src: v.src })}
                            className={`relative aspect-square overflow-hidden rounded-lg bg-ink-900 ring-2 hover:ring-gold-500 ${video && (video.embed === v.embed && video.src === v.src) ? 'ring-gold-500' : 'ring-transparent'}`}
                        >
                            {v.thumb && <img src={v.thumb} alt="" className="h-full w-full object-cover opacity-80" />}
                            <span className="absolute inset-0 grid place-items-center">
                                <span className="bg-white/90 rounded-full w-8 h-8 grid place-items-center">
                                    <svg className="w-4 h-4 text-ink-900" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z" /></svg>
                                </span>
                            </span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ── Buy box ──────────────────────────────────────────────────────────────── */
function BuyBox({ product, purchase, offerTiers, pdpOffers, myOffers, memberBanner, delivery, reviews, loved, lovesCount, ui }) {
    const { add } = useCart();
    const { props } = usePage();
    const trustBadges = props.chrome?.footer?.trustBadges || [];
    const preorder = product.preorder;

    const addToCart = async () => {
        if (!purchase.canBuy) return;
        const eventId = purchase.makeAddEvent();
        await add(product.add_url, {
            variant_id: purchase.variantId === 'none' ? '' : purchase.variantId,
            qty: purchase.qty,
            event_id: eventId,
        });
    };

    // Buy now → Inertia POST; the server adds the line and redirects to
    // /checkout, which then renders in place (no page reload).
    const [buying, setBuying] = useState(false);
    const buyNow = () => {
        if (!purchase.canBuy || buying) return;
        purchase.makeAddEvent();
        setBuying(true);
        router.post(product.buynow_url, {
            variant_id: purchase.variantId === 'none' ? '' : purchase.variantId,
            qty: purchase.qty,
        }, { onFinish: () => setBuying(false) });
    };

    const hasBundle = offerTiers.some((t) => t.min_qty >= 2);
    const allPreorder = offerTiers.length > 0 && offerTiers.every((t) => t.preorder_only);

    return (
        <div>
            <h1 className="font-display text-3xl font-semibold">{product.name}</h1>

            <a href="#reviews" className="mt-2 flex items-center gap-2 text-sm group">
                <span className="flex text-gold-500">
                    {[1, 2, 3, 4, 5].map((i) => <Star key={i} off={!reviews.avg || i > Math.round(reviews.avg)} />)}
                </span>
                <span className="text-ink-700/50 group-hover:text-gold-700">
                    {reviews.count ? `${reviews.avg} · ${reviews.count} review${reviews.count > 1 ? 's' : ''}` : (lovesCount > 0 ? `Loved by ${lovesCount} ${lovesCount === 1 ? 'person' : 'people'}` : 'Be the first to review')}
                </span>
            </a>

            <div className="flex items-center gap-4 flex-wrap">
                <LoveButton url={product.love_url} loved={loved} count={lovesCount} />
                <ShareButton url={product.url} title={product.name} label="Share" />
            </div>

            <div className="mt-4 flex items-baseline gap-3 flex-wrap">
                <span className="text-2xl font-semibold text-gold-700">{purchase.priceText()}</span>
                {purchase.onSale && (!purchase.hasVariants || purchase.matched) && (
                    <>
                        <span className="text-ink-400 line-through">{money(purchase.compareAt)}</span>
                        <span className="badge bg-danger-100 text-danger-700">Save {purchase.discountPct}%</span>
                    </>
                )}
                {purchase.offerPercent > 0 && (
                    <span className="badge bg-success-100 text-success-700">Bundle price · save {money(purchase.savings)}</span>
                )}
            </div>

            {memberBanner && (
                <div className="mt-3 rounded-lg bg-gold-100/70 border border-gold-300 px-3 py-2.5 text-sm text-gold-800 flex items-center gap-2">
                    <Icon name="medal" className="w-5 h-5 shrink-0" />
                    <span>
                        <strong>Member price {memberBanner.price_text}</strong>{' '}
                        <span className="text-gold-700/70">· save {memberBanner.pct}%{memberBanner.savings_text ? ` (${memberBanner.savings_text})` : ''}</span>
                        {' '}— applied automatically at checkout.
                    </span>
                </div>
            )}

            {/* Guests: say what membership is worth right where the price is. */}
            {!ui.isMember && ui.registerPct && (
                <a
                    href={ui.registerUrl}
                    className="mt-4 flex items-center gap-3 rounded-xl border border-gold-300 bg-gradient-to-r from-gold-100/70 to-white px-4 py-3 hover:border-gold-400 transition-colors group"
                >
                    <Icon name="medal" className="w-5 h-5 shrink-0" />
                    <span className="min-w-0 flex-1">
                        <span className="block text-sm font-semibold text-gold-800">
                            Members get {ui.registerPct}% off this piece
                        </span>
                        <span className="block text-xs text-ink-700/65 mt-0.5">
                            Free to join — plus loyalty points on every order and members-only offers.
                        </span>
                    </span>
                    <span className="shrink-0 text-sm font-medium text-gold-700 group-hover:underline whitespace-nowrap">Join free →</span>
                </a>
            )}

            {product.short_description && <p className="mt-4 text-ink-700/80">{product.short_description}</p>}

            {/* Quantity / bundle offer tiers */}
            {offerTiers.length > 0 && (
                <div className={`mt-5 rounded-xl border p-4 ${allPreorder ? 'border-promo-200 bg-promo-50/60' : 'border-gold-200 bg-gold-50/60'}`}>
                    <p className="text-sm font-semibold text-ink-800 flex items-center gap-1.5">
                        <Icon name={allPreorder ? 'calendar' : 'gift'} className="w-4 h-4 shrink-0" />
                        {allPreorder ? 'Pre-order offer' : hasBundle ? 'Buy more, save more' : 'Offer on this piece'}
                    </p>
                    <div className="mt-3 grid gap-2">
                        {offerTiers.map((tier, i) => {
                            const applied = purchase.qty >= tier.min_qty;
                            return (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => purchase.setQty(Math.max(purchase.qty, tier.min_qty))}
                                    className={`relative w-full text-left rounded-lg border px-3 py-2.5 text-sm transition ${tier.preorder_only ? 'border-promo-300' : (tier.highlight ? 'border-gold-400 shadow-sm' : 'border-ink-100')} ${applied ? 'border-success-500 bg-success-50' : 'hover:border-gold-300 bg-white'}`}
                                >
                                    {tier.badge && (
                                        <span className={`absolute -top-2 right-3 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-white ${tier.preorder_only ? 'bg-promo-600' : 'bg-gold-600'}`}>
                                            {tier.badge}
                                        </span>
                                    )}
                                    <span className="flex items-center justify-between gap-3">
                                        <span className="font-medium">{tier.label}</span>
                                        {applied && <span className="text-xs text-success-700 font-medium shrink-0 inline-flex items-center gap-1"><Icon name="check" className="w-3.5 h-3.5" />applied</span>}
                                    </span>
                                    <span className="mt-0.5 block text-xs text-ink-700/60">
                                        {tier.min_qty >= 2
                                            ? `${tier.each_text} each · save ${tier.save_total_text} on ${tier.min_qty}`
                                            : `${tier.each_text} instead of ${tier.price_text} · you save ${tier.save_each_text}`}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {/* Storewide offers shown on the PDP */}
            {pdpOffers.length > 0 && (
                <div className="mt-5 rounded-xl border border-success-200 bg-success-50/70 p-4">
                    <p className="text-sm font-semibold text-success-800 flex items-center gap-1.5"><Icon name="sparkle" className="w-4 h-4 shrink-0" />Offers on this order</p>
                    <ul className="mt-2 space-y-1.5">
                        {pdpOffers.map((o, i) => (
                            <li key={i} className="flex items-start gap-2 text-sm text-ink-800">
                                <Icon name="check" className="w-4 h-4 text-success-600 mt-0.5 shrink-0" />
                                <span>
                                    {o.badge && <span className="badge bg-success-600 text-white text-[10px] mr-1">{o.badge}</span>}
                                    <strong>{o.title}</strong>
                                    {o.description && <> — <span className="text-ink-700/70">{o.description}</span></>}
                                    {o.members_only && !ui.isMember && <> · <a href={ui.registerUrl} className="text-gold-700 underline">Register to unlock</a></>}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {/* Personalised offers */}
            {myOffers.length > 0 && (
                <div className="mt-5 rounded-xl border-2 border-gold-400 bg-gradient-to-r from-gold-100/80 to-white p-4">
                    <p className="text-sm font-semibold text-gold-800 flex items-center gap-1.5"><Icon name="gift" className="w-4 h-4 shrink-0" />Exclusive offer just for you</p>
                    <ul className="mt-2 space-y-2">
                        {myOffers.map((o, i) => (
                            <li key={i} className="text-sm text-ink-800">
                                <span className="badge bg-gold-600 text-white text-[10px] mr-1">{o.reward}</span>
                                <strong>{o.title}</strong>
                                {o.message && <span className="block text-xs text-ink-700/70 italic mt-0.5">{o.message}</span>}
                                <span className="block text-xs text-success-700 mt-0.5">Applied automatically at checkout{o.until ? ` · until ${o.until}` : ''}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {delivery && (
                <div className="mt-4 flex items-center gap-2 text-sm text-ink-700/80">
                    <Icon name="truck" className="w-5 h-5 text-gold-700" />
                    <span>Order now — estimated delivery <strong>{delivery.from}{delivery.to ? ` – ${delivery.to}` : ''}</strong></span>
                </div>
            )}

            {product.low_stock != null && (
                <div className="mt-4 flex items-center gap-2 text-sm text-danger-600 font-medium">
                    <span className="relative flex h-2 w-2">
                        <span className="animate-ping absolute h-2 w-2 rounded-full bg-danger-400 opacity-75"></span>
                        <span className="relative rounded-full h-2 w-2 bg-danger-500"></span>
                    </span>
                    Hurry — only {product.low_stock} left in stock!
                </div>
            )}

            {/* Variant picker */}
            {purchase.hasVariants && (
                <div className="mt-6 space-y-4">
                    <AttributePickers purchase={purchase} />
                    {!purchase.matched && <p className="text-xs text-ink-700/50">Select options to see price &amp; availability.</p>}
                    {purchase.matched && purchase.variantStock <= 0 && <p className="text-xs text-danger-600 font-medium">This combination is sold out.</p>}
                </div>
            )}

            {/* Quantity */}
            <div className="mt-6 flex items-center gap-4">
                <div className="inline-flex items-center rounded-md border border-ink-100">
                    <button type="button" onClick={() => purchase.setQty(Math.max(1, purchase.qty - 1))} className="px-3 py-2.5">−</button>
                    <span className="w-10 text-center">{purchase.qty}</span>
                    <button type="button" onClick={() => purchase.setQty(purchase.qty + 1)} className="px-3 py-2.5">+</button>
                </div>
                <span className="text-sm text-ink-700/50">Cash on delivery available</span>
            </div>

            {preorder && (
                <div className="mt-5 rounded-xl border border-promo-200 bg-promo-50 p-4">
                    <p className="text-sm font-semibold text-promo-800 flex items-center gap-1.5"><Icon name="calendar" className="w-4 h-4 shrink-0" />Pre-order item</p>
                    <p className="text-sm text-promo-700/80 mt-1">
                        {preorder.note}
                        <br />Estimated delivery: <strong>{preorder.eta}</strong>.
                    </p>
                </div>
            )}

            {/* Actions */}
            {(product.available || preorder) ? (
                <>
                    <div className="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <button type="button" onClick={addToCart} disabled={!purchase.canBuy} className="btn-outline w-full">
                            {preorder ? 'Add pre-order to cart' : 'Add to cart'}
                        </button>
                        <button type="button" onClick={buyNow} disabled={!purchase.canBuy || buying} className={`w-full ${preorder ? 'inline-flex items-center justify-center rounded-md bg-promo-600 px-4 py-2.5 font-medium text-white hover:bg-promo-700 transition disabled:opacity-50' : 'btn-primary'}`}>
                            {buying ? 'Taking you to checkout…' : (preorder ? 'Book now (Pre-order)' : 'Buy now')}
                        </button>
                    </div>
                    {!purchase.canBuy && <p className="mt-2 text-xs text-danger-600">Please choose an option above.</p>}
                </>
            ) : (
                <>
                    <button disabled className="btn-dark w-full mt-5 opacity-60">Sold out</button>
                    {ui.webPushReady && <StockNotify productId={product.id} />}
                </>
            )}

            {ui.whatsapp && (
                <a href={ui.whatsapp.url} target="_blank" rel="noopener" className="mt-3 flex items-center justify-center gap-2 rounded-md border border-[#25D366]/40 bg-[#25D366]/10 px-4 py-2.5 text-sm font-medium text-[#128C7E] hover:bg-[#25D366]/20 transition">
                    <WhatsApp />
                    Order or ask on WhatsApp
                </a>
            )}

            {/* Trust badges */}
            {trustBadges.length > 0 && (
                <div className="mt-6 grid grid-cols-3 gap-3">
                    {trustBadges.map((b, i) => (
                        <div key={i} className="rounded-lg border border-gold-100 bg-white px-3 py-2.5 text-center">
                            {b.icon && <div className="text-xl">{b.icon}</div>}
                            <div className="text-xs font-medium mt-0.5">{b.title}</div>
                            {b.text && <div className="text-[11px] text-ink-700/50">{b.text}</div>}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

function AttributePickers({ purchase }) {
    return (
        <>
            {(purchase.attributesList || []).map((attr) => (
                <div key={attr.name}>
                    <span className="label">
                        {attr.name}: <span className="text-ink-700/60 font-normal">{purchase.selected[attr.name] || 'Choose'}</span>
                    </span>
                    <div className="flex flex-wrap gap-2">
                        {attr.values.map((val) => {
                            const inStock = purchase.valueInStock(attr.name, val);
                            const isSel = String(purchase.selected[attr.name]) === String(val);
                            return (
                                <button
                                    key={val}
                                    type="button"
                                    disabled={!inStock}
                                    onClick={() => purchase.selectAttr(attr.name, val)}
                                    className={`rounded-md border px-4 py-2 text-sm transition ${isSel ? 'border-gold-500 bg-gold-100 text-gold-800' : 'border-ink-100 hover:border-gold-300'} ${!inStock ? 'opacity-40 line-through cursor-not-allowed' : ''}`}
                                >
                                    {val}
                                </button>
                            );
                        })}
                    </div>
                </div>
            ))}
        </>
    );
}

function LoveButton({ url, loved: initialLoved, count: initialCount }) {
    const [loved, setLoved] = useState(initialLoved);
    const [count, setCount] = useState(initialCount);
    const [busy, setBusy] = useState(false);

    const toggle = async () => {
        if (busy) return;
        setBusy(true);
        // Optimistic flip; reconciled with the server response.
        setLoved(!loved);
        setCount(count + (loved ? -1 : 1));
        try {
            const data = await fetchJson(url, { method: 'POST' });
            setLoved(data.loved);
            setCount(data.count);
        } catch (e) {
            setLoved(loved);
            setCount(count);
        } finally {
            setBusy(false);
        }
    };

    return (
        <button type="button" onClick={toggle} className="mt-2 inline-flex items-center gap-1.5 text-sm text-ink-700/70 hover:text-danger-500 transition" aria-pressed={loved} title={loved ? 'Remove from loved' : 'Love this'}>
            <svg className={`w-5 h-5 transition ${loved ? 'text-danger-500' : ''}`} fill={loved ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M21 8.25c0-2.485-2.02-4.5-4.5-4.5-1.74 0-3.25.99-4 2.44-.75-1.45-2.26-2.44-4-2.44A4.5 4.5 0 003 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
            <span>{count > 0 ? count : 'Love'}</span>
        </button>
    );
}

function StockNotify({ productId }) {
    const [state, setState] = useState('idle');
    const [busy, setBusy] = useState(false);

    const notifyMe = async () => {
        if (busy || !window.wpEnsure) return;
        setBusy(true);
        try {
            const sub = await window.wpEnsure();
            if (!sub) { setState('denied'); return; }
            const res = await fetch('/push/watch-stock', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
                body: JSON.stringify({ product_id: productId, endpoint: sub.endpoint }),
            });
            setState(res.ok ? 'done' : 'idle');
        } catch (e) {
            setState('idle');
        } finally {
            setBusy(false);
        }
    };

    if (state === 'done') {
        return <p className="text-sm text-success-700 text-center mt-3 flex items-center justify-center gap-1.5"><Icon name="check" className="w-4 h-4" />We'll notify you the moment it's back in stock.</p>;
    }

    return (
        <div className="mt-3">
            <button type="button" onClick={notifyMe} disabled={busy} className="btn-outline w-full inline-flex items-center justify-center gap-2 disabled:opacity-50">
                <Icon name="bell" className="w-4 h-4" strokeWidth={1.6} />
                <span>{busy ? 'Setting up…' : 'Notify me when available'}</span>
            </button>
            {state === 'denied' && <p className="text-xs text-ink-700/60 text-center mt-1">Enable notifications in your browser to use this.</p>}
        </div>
    );
}

/* ── Below-the-fold sections ──────────────────────────────────────────────── */
function StorySections({ sections }) {
    if (!sections?.length) return null;
    return (
        <section className="mt-12 space-y-12 sm:space-y-20">
            {sections.map((s, i) => {
                const imageLeft = (s.layout || 'right') === 'left';
                return (
                    <div key={i} className="grid md:grid-cols-2 gap-6 md:gap-12 items-center">
                        {s.image && (
                            <div className={imageLeft ? 'md:order-1' : 'md:order-2'}>
                                <img src={s.image} alt={s.heading || ''} loading="lazy" className="w-full rounded-xl object-cover shadow-sm" />
                            </div>
                        )}
                        <div className={`${imageLeft ? 'md:order-2' : 'md:order-1'} ${!s.image ? 'md:col-span-2 text-center max-w-2xl mx-auto' : ''}`}>
                            {s.heading && <h2 className="font-display text-2xl sm:text-3xl font-semibold mb-3">{s.heading}</h2>}
                            {s.body && <p className="text-ink-700/75 leading-relaxed whitespace-pre-line">{s.body}</p>}
                        </div>
                    </div>
                );
            })}
        </section>
    );
}

function Description({ text }) {
    const [open, setOpen] = useState(false);
    if (!text) return null;
    return (
        <section className="mt-12 max-w-3xl border-t border-ink-100 pt-8">
            <button type="button" onClick={() => setOpen(!open)} className="w-full flex items-center justify-between gap-4 text-left">
                <h2 className="font-display text-2xl font-semibold">Description</h2>
                <Icon name="chevronDown" className={`w-6 h-6 shrink-0 text-ink-700/50 transition-transform duration-300 ${open ? 'rotate-180' : ''}`} strokeWidth={2} />
            </button>
            {/* Always in the DOM (collapsed via CSS) so search engines index the copy. */}
            <div className={`prose prose-sm max-w-none text-ink-700/85 mt-3 whitespace-pre-line ${open ? '' : 'hidden'}`}>{text}</div>
        </section>
    );
}

function Specs({ specs }) {
    if (!specs?.length) return null;
    return (
        <section className="mt-6 max-w-3xl">
            <h2 className="font-display text-lg font-semibold mb-2">Specifications</h2>
            <div className="flex flex-wrap gap-2">
                {specs.map((spec, i) => (
                    <div key={i} className="inline-flex items-center gap-2 rounded-lg bg-gold-50 border border-gold-100 px-4 py-2 text-sm">
                        <span className="text-ink-700/60">{spec.label}:</span>
                        <span className="font-medium">{spec.value}</span>
                    </div>
                ))}
            </div>
        </section>
    );
}

function Reviews({ product, reviews, ui, flash }) {
    const [open, setOpen] = useState(false);
    const [rating, setRating] = useState(0);
    const [hover, setHover] = useState(0);
    const { props } = usePage();
    const errors = props.errors || {};
    const errorMsg = Object.values(errors)[0]?.[0];

    const submit = (e) => {
        e.preventDefault();
        router.post(product.review_url, new FormData(e.target), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => setOpen(true),
        });
    };

    return (
        <section className="mt-16 border-t border-ink-100 pt-10" id="reviews">
            <button type="button" onClick={() => setOpen(!open)} className="w-full flex items-center justify-between gap-4 text-left">
                <h2 className="font-display text-2xl font-semibold flex items-center gap-3">
                    Customer reviews
                    {reviews.count > 0 && (
                        <span className="text-sm font-normal text-ink-700/60 flex items-center gap-1">
                            <Star className="w-4 h-4 text-gold-500" />
                            {Number(reviews.avg).toFixed(1)} · {reviews.count} review{reviews.count > 1 ? 's' : ''}
                        </span>
                    )}
                </h2>
                <Icon name="chevronDown" className={`w-6 h-6 shrink-0 text-ink-700/50 transition-transform duration-300 ${open ? 'rotate-180' : ''}`} strokeWidth={2} />
            </button>

            <div className={`grid md:grid-cols-3 gap-8 mt-6 ${open ? '' : 'hidden'}`}>
                {true && (
                <>
                    {reviews.perk > 0 && (
                        <div className="md:col-span-3">
                            {ui.isMember ? (
                                <div className="flex items-start gap-3 rounded-xl border border-gold-200 bg-gold-100/60 px-4 py-3 text-sm text-gold-800">
                                    <Icon name="gift" className="w-5 h-5 shrink-0" />
                                    <p><span className="font-semibold">Share a review, earn {reviews.perk.toLocaleString()} bonus points</span>{reviews.photoPerk > 0 ? ` — add a photo for +${reviews.photoPerk.toLocaleString()} more` : ''}. Points are added to your account as soon as your review is approved.</p>
                                </div>
                            ) : (
                                <div className="flex items-start gap-3 rounded-xl border border-ink-100 bg-white px-4 py-3 text-sm text-ink-700/80">
                                    <Icon name="gift" className="w-5 h-5 shrink-0" />
                                    <p>Members earn <span className="font-semibold text-gold-700">{reviews.perk.toLocaleString()} bonus points</span> for every approved review{reviews.photoPerk > 0 ? ` (+${reviews.photoPerk.toLocaleString()} with a photo)` : ''}. <a href={ui.loginUrl} className="font-medium text-gold-700 underline">Sign in</a> or <a href={ui.registerUrl} className="font-medium text-gold-700 underline">join free</a> to start earning.</p>
                                </div>
                            )}
                        </div>
                    )}

                    {/* Summary */}
                    <div>
                        {reviews.count ? (
                            <>
                                <div className="flex items-end gap-2">
                                    <span className="text-4xl font-semibold">{reviews.avg}</span>
                                    <span className="text-ink-700/50 mb-1">/ 5</span>
                                </div>
                                <div className="flex text-gold-500 mt-1">
                                    {[1, 2, 3, 4, 5].map((i) => <Star key={i} className="w-5 h-5" off={i > Math.round(reviews.avg)} />)}
                                </div>
                                <p className="text-sm text-ink-700/60 mt-1">{reviews.count} review{reviews.count > 1 ? 's' : ''}</p>
                                <div className="mt-4 space-y-1.5">
                                    {Object.entries(reviews.dist).sort((a, b) => b[0] - a[0]).map(([star, n]) => (
                                        <div key={star} className="flex items-center gap-2 text-xs">
                                            <span className="w-6 text-ink-700/60">{star}★</span>
                                            <div className="flex-1 h-2 rounded-full bg-ink-100 overflow-hidden">
                                                <div className="h-full bg-gold-500" style={{ width: `${reviews.count ? Math.round(n / reviews.count * 100) : 0}%` }} />
                                            </div>
                                            <span className="w-6 text-right text-ink-700/50">{n}</span>
                                        </div>
                                    ))}
                                </div>
                            </>
                        ) : (
                            <p className="text-ink-700/60">No reviews yet. Be the first to review this piece!</p>
                        )}
                    </div>

                    {/* List + write form */}
                    <div className="md:col-span-2 space-y-6">
                        {reviews.items.map((review, i) => (
                            <div key={i} className="border-b border-ink-100 pb-5 last:border-0">
                                <div className="flex items-center gap-2 flex-wrap">
                                    <span className="flex text-gold-500">
                                        {[1, 2, 3, 4, 5].map((s) => <Star key={s} off={s > review.rating} />)}
                                    </span>
                                    <span className="font-medium text-sm">{review.author}</span>
                                    {review.verified && <span className="badge bg-success-100 text-success-700 text-[10px]">Verified buyer</span>}
                                    <span className="text-xs text-ink-700/40 ml-auto">{review.date}</span>
                                </div>
                                {review.title && <p className="font-medium mt-2">{review.title}</p>}
                                {review.body && <p className="text-sm text-ink-700/80 mt-1">{review.body}</p>}
                                {review.photos.length > 0 && (
                                    <div className="mt-3 flex gap-2 flex-wrap">
                                        {review.photos.map((url, j) => <img key={j} src={url} className="w-20 h-20 rounded-lg object-cover border border-ink-100" alt="Customer photo" loading="lazy" />)}
                                    </div>
                                )}
                            </div>
                        ))}

                        <details className="rounded-xl border border-ink-100 p-5">
                            <summary className="font-medium cursor-pointer">
                                <span className="inline-flex items-center gap-1.5"><Icon name="pen" className="w-4 h-4 shrink-0" />Write a review</span>
                                {reviews.perk > 0 && ui.isMember && <span className="badge bg-gold-100 text-gold-800 ml-1">+{reviews.perk.toLocaleString()} points</span>}
                            </summary>
                            {flash?.success && <p className="mt-3 text-sm text-success-700 bg-success-50 rounded p-2">{flash.success}</p>}
                            {errorMsg && <div className="mt-3 text-sm text-danger-700 bg-danger-50 rounded p-2">{errorMsg}</div>}
                            <form onSubmit={submit} className="mt-4 space-y-3" encType="multipart/form-data">
                                <div>
                                    <span className="label">Your rating *</span>
                                    <div className="flex gap-1" onMouseLeave={() => setHover(0)}>
                                        {[1, 2, 3, 4, 5].map((i) => (
                                            <button key={i} type="button" onClick={() => setRating(i)} onMouseEnter={() => setHover(i)} className={`text-2xl transition ${(hover || rating) >= i ? 'text-gold-500' : 'text-ink-200'}`}>★</button>
                                        ))}
                                    </div>
                                    <input type="hidden" name="rating" value={rating || ''} required />
                                </div>
                                <div className="grid sm:grid-cols-2 gap-3">
                                    <input name="author_name" placeholder="Your name *" className="input" required defaultValue={ui.customerName || ''} />
                                    <input name="phone" placeholder="Phone (for verified badge)" className="input" />
                                </div>
                                <input name="title" placeholder="Headline (optional)" className="input" />
                                <textarea name="body" rows={3} placeholder="Share your experience…" className="input" />
                                <div>
                                    <label className="label">Add photos (optional, up to 4)</label>
                                    <input type="file" name="photos[]" accept="image/*" multiple className="input text-sm" />
                                </div>
                                <button className="btn-primary" disabled={!rating}>Submit review</button>
                                <p className="text-xs text-ink-700/50">Reviews appear after approval.</p>
                            </form>
                        </details>
                    </div>
                </>
                )}
            </div>
        </section>
    );
}

function FrequentlyBought({ fbt }) {
    const [sel, setSel] = useState(() => {
        const init = {};
        (fbt?.items || []).forEach((p) => { init[p.id] = p.selectable; });
        return init;
    });
    const { showToast, refresh } = useCart();
    if (!fbt) return null;

    const total = fbt.items.reduce((t, p) => t + (sel[p.id] ? p.price : 0), 0);
    const none = !Object.values(sel).some(Boolean);

    // "Buy now" → server redirects to /checkout (rendered in place);
    // "Add selected" → server redirects back here with a flash, and the
    // mini-cart badge refreshes from the new props.
    const submitBundle = (redirect) => {
        router.post(fbt.add_many_url, {
            product_ids: fbt.items.filter((p) => p.selectable && sel[p.id]).map((p) => p.id),
            redirect,
        }, {
            preserveScroll: true,
            onSuccess: () => { if (!redirect) refresh(); },
        });
    };

    return (
        <section className="mt-16">
            <h2 className="font-display text-2xl font-semibold mb-6">Frequently bought together</h2>
            <div className="grid lg:grid-cols-3 gap-6 items-start">
                <div className="lg:col-span-2 flex flex-wrap items-center gap-3">
                    {fbt.items.map((p, i) => (
                        <span key={p.id} className="contents">
                            <label className="relative block w-32">
                                <input
                                    type="checkbox"
                                    className="absolute top-2 left-2 z-10 h-4 w-4 accent-gold-600"
                                    checked={!!sel[p.id]}
                                    disabled={!p.selectable}
                                    onChange={(e) => setSel({ ...sel, [p.id]: e.target.checked })}
                                />
                                <span className="block aspect-square rounded-lg overflow-hidden bg-gold-100 border border-ink-100">
                                    {p.thumb && <img src={p.thumb} className="h-full w-full object-cover" alt={p.name} />}
                                </span>
                                <span className="mt-1 block text-xs truncate">{p.name}</span>
                                <span className="block text-xs font-semibold text-gold-700">{p.price_text}</span>
                                {p.has_variants && <span className="block text-[10px] text-ink-700/50">choose options on its page</span>}
                            </label>
                            {i < fbt.items.length - 1 && <span className="text-2xl text-ink-300">+</span>}
                        </span>
                    ))}
                </div>
                <div className="card p-5">
                    <p className="text-sm text-ink-700/70">Total for selected</p>
                    <p className="text-2xl font-semibold text-gold-700">{money(total)}</p>
                    <div className="mt-3 space-y-2">
                        <button type="button" className="btn-primary w-full" disabled={none} onClick={() => submitBundle('checkout')}>Buy now</button>
                        <button type="button" className="btn-outline w-full" disabled={none} onClick={() => submitBundle('')}>Add selected to cart</button>
                    </div>
                </div>
            </div>
        </section>
    );
}

function CardStrip({ title, products, cols }) {
    if (!products?.length) return null;
    return (
        <section className="mt-16">
            <h2 className="font-display text-2xl font-semibold mb-6">{title}</h2>
            <div className={`grid ${cols} gap-x-4 gap-y-8`}>
                {products.map((p) => <ProductCard key={p.id} product={p} />)}
            </div>
        </section>
    );
}

function StickyBar({ product, purchase, ui }) {
    const { add } = useCart();
    const [scrolled, setScrolled] = useState(false);
    const preorder = product.preorder;

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 700);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    if (!ui.stickyBar || (!product.available && !preorder)) return null;

    const visible = scrolled || (typeof window !== 'undefined' && window.innerWidth < 768);

    const addToCart = async () => {
        if (!purchase.canBuy) return;
        const eventId = purchase.makeAddEvent();
        await add(product.add_url, {
            variant_id: purchase.variantId === 'none' ? '' : purchase.variantId,
            qty: purchase.qty,
            event_id: eventId,
        });
    };

    return (
        <>
            <div className={`fixed bottom-14 md:bottom-0 inset-x-0 z-40 bg-white border-t border-ink-100 shadow-[0_-4px_12px_rgba(0,0,0,0.06)] transition-transform ${visible ? 'translate-y-0' : 'translate-y-full'}`}>
                <div className="mx-auto max-w-7xl px-4 py-3 flex items-center gap-3">
                    <div className="shrink-0 hidden sm:block">
                        <div className="font-medium text-sm truncate max-w-[14rem]">{product.name}</div>
                    </div>
                    <div className="shrink-0">
                        <div className="text-xs text-ink-700/50">Total</div>
                        <div className="font-semibold text-gold-700">{money(purchase.price * purchase.qty)}</div>
                    </div>
                    <button type="button" onClick={addToCart} disabled={!purchase.canBuy} className="btn-outline whitespace-nowrap hidden sm:inline-flex">Add to cart</button>
                    <button
                        type="button"
                        onClick={() => {
                            if (!purchase.canBuy) return;
                            purchase.makeAddEvent();
                            router.post(product.buynow_url, { variant_id: purchase.variantId === 'none' ? '' : purchase.variantId, qty: purchase.qty });
                        }}
                        disabled={!purchase.canBuy}
                        className={`flex-1 ${preorder ? 'inline-flex items-center justify-center rounded-md bg-promo-600 px-4 py-2.5 font-medium text-white hover:bg-promo-700 transition disabled:opacity-50' : 'btn-primary'}`}
                    >
                        {preorder ? 'Book now' : 'Buy now'}
                    </button>
                </div>
            </div>
            <div className="md:hidden h-32" />
        </>
    );
}
