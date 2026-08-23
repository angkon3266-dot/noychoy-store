import { Link, router } from '@inertiajs/react';
import Icon, { Star } from './Icons';
import { useCart } from './CartContext';

// Storefront product card — data shape comes from ProductCardData::make().
export default function ProductCard({ product: p }) {
    const { add } = useCart();

    const soldOut = !p.available && !p.preorder;

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
                            {...(p.thumb450 ? { srcSet: `${p.thumb450} 450w, ${p.thumb} 1200w`, sizes: '(min-width: 768px) 25vw, 50vw' } : {})}
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
                            onClick={() => add(p.add_url, { variant_id: '', qty: 1 })}
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
                            onClick={() => router.post(p.buynow_url, { variant_id: '', qty: 1 })}
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
