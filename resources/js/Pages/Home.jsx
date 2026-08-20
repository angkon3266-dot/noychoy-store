import { useEffect, useRef, useState } from 'react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import Carousel from '../Shared/Carousel';
import HomeBlocks from '../Shared/HomeBlocks';
import SmartLink from '../Shared/SmartLink';
import useCountdown from '../Shared/useCountdown';

// The React homepage — a faithful port of the "couture" Blade template:
// editorial split hero with cross-fading slides, deals of the day, category
// lookbook, featured edit, promise band, new arrivals, then builder blocks.
export default function Home({ hero, deals, categoriesSection, featured, promise, newArrivals, blocks }) {
    return (
        <>
            <Hero hero={hero} />
            <Deals deals={deals} />
            <CategoryLookbook section={categoriesSection} />
            <Featured featured={featured} />
            <Promise promise={promise} />
            <NewArrivals section={newArrivals} />
            <HomeBlocks blocks={blocks} />
        </>
    );
}

Home.layout = (page) => <Layout>{page}</Layout>;

/* ── Hero: editorial split with cross-fade slideshow ─────────────────────── */
function Hero({ hero }) {
    const slides = hero.slides || [];
    const [i, setI] = useState(0);
    const timer = useRef(null);
    const touchX = useRef(null);

    const go = (k) => setI(((k % slides.length) + slides.length) % slides.length);

    const start = () => {
        stop();
        if (slides.length > 1) timer.current = setInterval(() => setI((v) => (v + 1) % slides.length), 5500);
    };
    const stop = () => { clearInterval(timer.current); timer.current = null; };

    useEffect(() => { start(); return stop; }, [slides.length]);

    return (
        <section className="relative">
            <div className="mx-auto max-w-7xl grid lg:grid-cols-2 items-stretch">
                <div className="flex items-center px-6 sm:px-10 py-10 sm:py-16 lg:py-28 order-2 lg:order-1">
                    <div className="max-w-lg">
                        <p className="uppercase tracking-[0.35em] text-[11px] text-gold-700 mb-5">{hero.eyebrow}</p>
                        <h1 className="font-display text-4xl sm:text-5xl lg:text-6xl leading-[1.05] text-ink-900" dangerouslySetInnerHTML={{ __html: hero.headingHtml }} />
                        <p className="mt-6 text-ink-700/70 text-lg leading-relaxed">{hero.subtitle}</p>
                        <div className="mt-9 flex flex-wrap gap-4">
                            <SmartLink href={hero.ctaLink} className="inline-flex items-center gap-2 rounded-full bg-ink-900 text-white px-7 py-3.5 text-sm tracking-wide hover:bg-ink-800 transition">
                                {hero.ctaText}
                                <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path strokeLinecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                            </SmartLink>
                            {hero.secondaryText && (
                                <a href={hero.secondaryLink} className="inline-flex items-center px-6 py-3.5 text-sm tracking-wide border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition">{hero.secondaryText}</a>
                            )}
                        </div>
                        <div className="mt-10 flex items-center gap-6 text-xs text-ink-700/50">
                            <span>★★★★★ Loved by customers</span><span>·</span><span>Cash on delivery</span><span>·</span><span>Nationwide</span>
                        </div>
                    </div>
                </div>

                <div
                    className="order-1 lg:order-2 relative min-h-[34vh] sm:min-h-[42vh] lg:min-h-[80vh] bg-gold-100 overflow-hidden group"
                    onMouseEnter={stop}
                    onMouseLeave={start}
                    onTouchStart={(e) => { touchX.current = e.changedTouches[0].clientX; stop(); }}
                    onTouchEnd={(e) => {
                        if (touchX.current !== null) {
                            const dx = e.changedTouches[0].clientX - touchX.current;
                            if (Math.abs(dx) > 40) go(dx < 0 ? i + 1 : i - 1);
                            touchX.current = null;
                        }
                        start();
                    }}
                >
                    {slides.map((s, k) => {
                        const active = slides.length <= 1 || i === k;
                        const cls = `absolute inset-0 block transition-opacity duration-1000 ease-out ${active ? 'opacity-100' : 'opacity-0 pointer-events-none'}`;
                        const inner = s.type === 'video' && s.video?.type === 'file' ? (
                            <video src={s.video.src} autoPlay muted loop playsInline className="w-full h-full object-cover" />
                        ) : s.type === 'video' ? (
                            <iframe src={s.video.embedUrl} title={s.alt} className="w-full h-full pointer-events-none" loading="lazy" allow="autoplay" tabIndex={-1} />
                        ) : (
                            <img
                                src={s.image}
                                alt={s.alt}
                                {...(k > 0 ? { loading: 'lazy' } : { fetchPriority: 'high' })}
                                {...(s.image900 ? { srcSet: `${s.image900} 900w, ${s.image} 1600w`, sizes: '(min-width: 1024px) 50vw, 100vw' } : {})}
                                className="w-full h-full object-cover transition-transform duration-700 ease-out group-hover:scale-105"
                            />
                        );
                        return s.link ? (
                            <SmartLink key={k} href={s.link} aria-label={s.alt || 'View the collection'} aria-hidden={!active} className={cls}>{inner}</SmartLink>
                        ) : (
                            <div key={k} aria-hidden={!active} className={cls}>{inner}</div>
                        );
                    })}

                    <div className="absolute inset-0 bg-gradient-to-t from-black/10 to-transparent pointer-events-none"></div>

                    {slides.length > 1 && (
                        <>
                            <button type="button" onClick={() => go(i - 1)} aria-label="Previous"
                                className="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/75 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">‹</button>
                            <button type="button" onClick={() => go(i + 1)} aria-label="Next"
                                className="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/75 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">›</button>
                            <div className="absolute bottom-1 inset-x-0 flex justify-center">
                                {slides.map((_, k) => (
                                    <button key={k} type="button" onClick={() => go(k)} aria-label={`Go to slide ${k + 1}`} className="p-2.5 grid place-items-center">
                                        <span className={`h-1.5 rounded-full transition-all duration-300 ${i === k ? 'bg-white w-5' : 'bg-white/55 w-1.5'}`}></span>
                                    </button>
                                ))}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </section>
    );
}

/* ── Deals of the Day ─────────────────────────────────────────────────────── */
function Deals({ deals }) {
    if (!deals) return null;
    return <DealsInner deals={deals} />;
}

function DealsInner({ deals }) {
    const { expired, units } = useCountdown(deals.endsAt);
    if (deals.endsAt && expired) return null;

    return (
        <section className="mx-auto max-w-7xl px-4 py-12 lg:py-16">
            <div className="flex flex-wrap items-end justify-between gap-4 mb-8">
                <div>
                    <p className="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Today only</p>
                    <h2 className="font-display text-3xl sm:text-4xl text-ink-900">{deals.title}</h2>
                    {deals.subtitle && <p className="mt-2 text-ink-700/60">{deals.subtitle}</p>}
                </div>
                {deals.endsAt && (
                    <div className="flex items-center gap-2">
                        <span className="text-xs uppercase tracking-widest text-ink-700/50 mr-1">Ends in</span>
                        {units.map((u) => (
                            <div key={u.label} className="rounded-lg bg-ink-900 text-white px-2.5 py-1.5 text-center w-12">
                                <div className="text-base font-semibold tabular-nums">{String(u.value).padStart(2, '0')}</div>
                                <div className="text-[9px] uppercase tracking-widest text-white/50">{u.label}</div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <Carousel>
                {deals.cards.map((card, i) => (
                    <SmartLink key={i} href={card.href}
                        className="group snap-start shrink-0 w-[280px] rounded-2xl overflow-hidden border border-ink-100 bg-white flex flex-col hover:-translate-y-2 hover:shadow-lg transition duration-300">
                        <div className="relative h-44 bg-gold-100 overflow-hidden shrink-0">
                            {card.image && <img src={card.image} alt={card.title} loading="lazy" className="w-full h-full object-cover transition-transform duration-700 group-hover:scale-105" />}
                            {card.discount && <span className="absolute top-3 left-3 rounded-full bg-ink-900 text-white text-[11px] font-medium px-3 py-1">{card.discount}</span>}
                        </div>
                        <div className="flex-1 p-5 flex flex-col justify-between gap-4">
                            <div className="space-y-2">
                                <p className="flex items-center gap-1.5 text-[11px] uppercase tracking-wider text-gold-700">
                                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.878.53 2.31-.354A11.95 11.95 0 0021 15.75c0-.98-.117-1.933-.338-2.846M6.375 6.375h.008v.008H6.375V6.375z" /></svg>
                                    {card.tag}
                                </p>
                                <h3 className="font-semibold text-ink-900 leading-snug">{card.title}</h3>
                                {card.description && <p className="text-sm text-ink-700/60 line-clamp-2">{card.description}</p>}
                            </div>
                            <div className="flex items-center justify-between pt-3 border-t border-ink-100">
                                <span className="text-xs tracking-wide text-ink-700/60">Shop this deal</span>
                                <span className="w-8 h-8 rounded-full bg-ink-50 text-ink-900 grid place-items-center transition duration-300 group-hover:-rotate-45 group-hover:bg-ink-900 group-hover:text-white">
                                    <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path strokeLinecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                                </span>
                            </div>
                        </div>
                    </SmartLink>
                ))}
            </Carousel>
        </section>
    );
}

/* ── Category lookbook (first tile large) ─────────────────────────────────── */
function CategoryLookbook({ section }) {
    if (!section.show || !section.items.length) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 py-16 lg:py-24">
            <div className="flex items-end justify-between mb-10">
                <div>
                    <p className="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Explore</p>
                    <h2 className="font-display text-3xl sm:text-4xl text-ink-900">{section.title}</h2>
                </div>
                <SmartLink href="/shop" className="hidden sm:inline-block text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5">View all</SmartLink>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 lg:gap-6">
                {section.items.map((cat, i) => (
                    <SmartLink key={cat.url} href={cat.url}
                        className={`group relative block overflow-hidden rounded-2xl bg-gold-100 ${i === 0 ? 'md:col-span-2 md:row-span-2 aspect-[4/3] md:aspect-auto' : 'aspect-square'}`}>
                        {cat.image && <img src={cat.image} alt={cat.name} className="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105" />}
                        <div className="absolute inset-0 bg-gradient-to-t from-black/55 via-black/10 to-transparent"></div>
                        <div className="absolute bottom-0 left-0 p-5">
                            <h3 className="font-display text-xl lg:text-2xl text-white">{cat.name}</h3>
                            <span className="text-white/80 text-xs tracking-wide inline-flex items-center gap-1 mt-1">
                                Discover
                                <svg className="w-3.5 h-3.5 transition group-hover:translate-x-1" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                            </span>
                        </div>
                    </SmartLink>
                ))}
            </div>
        </section>
    );
}

function Featured({ featured }) {
    if (!featured.cards.length) return null;
    return (
        <section className="bg-gold-50/60 py-16 lg:py-24">
            <div className="mx-auto max-w-7xl px-4">
                <div className="text-center max-w-2xl mx-auto mb-12">
                    <p className="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Curated</p>
                    <h2 className="font-display text-3xl sm:text-4xl text-ink-900">{featured.title}</h2>
                    <p className="mt-3 text-ink-700/60">Our most-loved pieces, chosen for the season.</p>
                </div>
                <div className="grid grid-cols-2 md:grid-cols-4 gap-x-5 gap-y-10">
                    {featured.cards.map((p) => <ProductCard key={p.id} product={p} />)}
                </div>
                <div className="text-center mt-12">
                    <SmartLink href="/shop" className="inline-flex items-center gap-2 rounded-full border border-ink-900/20 px-8 py-3.5 text-sm tracking-wide hover:bg-ink-900 hover:text-white transition">Shop all jewelry</SmartLink>
                </div>
            </div>
        </section>
    );
}

function Promise({ promise }) {
    if (!promise.show) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 py-16 lg:py-24">
            <div className="grid lg:grid-cols-2 gap-10 lg:gap-16 items-center">
                <div className="relative aspect-[5/4] rounded-2xl overflow-hidden bg-gold-100">
                    {promise.image && <img src={promise.image} alt="" className="w-full h-full object-cover" loading="lazy" />}
                </div>
                <div className="max-w-md">
                    <p className="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-3">{promise.eyebrow}</p>
                    <h2 className="font-display text-3xl sm:text-4xl text-ink-900 leading-tight">{promise.title}</h2>
                    <p className="mt-5 text-ink-700/70 leading-relaxed">{promise.text}</p>
                    <div className="mt-8 grid grid-cols-3 gap-4 text-center">
                        {promise.badges.map((b, i) => (
                            <div key={i}>
                                <div className="font-display text-lg text-gold-700">{b.title}</div>
                                <p className="text-xs text-ink-700/60 mt-1">{b.text}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}

function NewArrivals({ section }) {
    if (!section.show || !section.cards.length) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 pb-20">
            <div className="flex items-end justify-between mb-10">
                <h2 className="font-display text-3xl sm:text-4xl text-ink-900">{section.title}</h2>
                <SmartLink href="/shop?sort=new" className="text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5">See what's new</SmartLink>
            </div>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-5 gap-y-10">
                {section.cards.map((p) => <ProductCard key={p.id} product={p} />)}
            </div>
        </section>
    );
}
