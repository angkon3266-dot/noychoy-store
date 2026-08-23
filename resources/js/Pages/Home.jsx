import { useEffect, useRef, useState } from 'react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import Carousel from '../Shared/Carousel';
import HomeBlocks from '../Shared/HomeBlocks';
import SmartLink from '../Shared/SmartLink';
import useCountdown from '../Shared/useCountdown';
import Icon, { IconOrGlyph, Star } from '../Shared/Icons';

// The Meridian Éclat homepage — conversion-first and gift-led.
//
// Order is deliberate (mobile-first, every step earns the next scroll):
//   hero → reassurance → shop by occasion (the gift path) → deals → best
//   sellers → gift finder by budget → categories → featured edit → how gifting
//   works → customer love → our promise → new arrivals → admin builder blocks.
// Every section is admin-toggleable/editable through the same home_content
// settings the old templates used; copy falls back to sensible defaults.
export default function Home(props) {
    const { hero, featureStrip, occasions, deals, bestSellers, giftFinder, categoriesSection, featured, reviews, promise, newArrivals, blocks, heroTrust } = props;
    return (
        <>
            <Hero hero={hero} hasReviews={reviews?.length > 0} trust={heroTrust} />
            <FeatureStrip strip={featureStrip} />
            <Occasions section={occasions} />
            <Deals deals={deals} />
            <CardSection section={bestSellers} eyebrow="Most loved" viewAll="/best-sellers" viewAllLabel="View all best sellers" />
            <GiftFinder finder={giftFinder} />
            <CategoryLookbook section={categoriesSection} />
            <Featured featured={featured} />
            <GiftingSteps giftFinder={giftFinder} />
            <ReviewsBand reviews={reviews} />
            <Promise promise={promise} />
            <CardSection section={newArrivals} eyebrow="Just in" viewAll="/shop?sort=new" viewAllLabel="See what's new" tinted />
            <HomeBlocks blocks={blocks} />
        </>
    );
}

Home.layout = (page) => <Layout>{page}</Layout>;

/* ── Shared section heading ─────────────────────────────────────────────── */
function Heading({ eyebrow, title, subtitle = null, action = null, center = false }) {
    return (
        <div className={`mb-10 ${center ? 'text-center max-w-2xl mx-auto' : 'flex items-end justify-between gap-6'}`}>
            <div>
                {eyebrow && <p className="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">{eyebrow}</p>}
                <h2 className="font-display text-3xl sm:text-4xl text-ink-900 leading-tight">{title}</h2>
                {subtitle && <p className="mt-3 text-ink-700/70">{subtitle}</p>}
            </div>
            {action && <div className="hidden sm:block shrink-0">{action}</div>}
        </div>
    );
}

const underlineLink = 'text-sm border-b border-ink-900/30 hover:border-gold-700 hover:text-gold-700 transition pb-0.5';

/* ── Hero: editorial split + cross-fading slideshow + gift entry ─────────── */
function Hero({ hero, hasReviews = false, trust = [] }) {
    const slides = hero.slides || [];
    const [i, setI] = useState(0);
    const timer = useRef(null);
    const touchX = useRef(null);

    // Only the current slide (and the one queued behind it) is put in the
    // DOM. They are all absolutely positioned inside the viewport, so
    // loading="lazy" does nothing — six hero images would all be fetched at
    // first paint, competing with the LCP image and the JS bundle.
    const [mounted, setMounted] = useState(() => new Set(slides.length > 1 ? [0, 1] : [0]));
    useEffect(() => {
        setMounted((prev) => {
            const next = new Set(prev).add(i).add((i + 1) % slides.length);
            return next.size === prev.size ? prev : next;
        });
    }, [i, slides.length]);

    const go = (k) => setI(((k % slides.length) + slides.length) % slides.length);
    const stop = () => { clearInterval(timer.current); timer.current = null; };
    const start = () => {
        stop();
        if (slides.length > 1) timer.current = setInterval(() => setI((v) => (v + 1) % slides.length), 5500);
    };
    useEffect(() => { start(); return stop; }, [slides.length]);

    return (
        <section className="relative">
            <div className="mx-auto max-w-7xl grid lg:grid-cols-2 items-stretch">
                <div className="flex items-center px-6 sm:px-10 py-10 sm:py-16 lg:py-24 order-2 lg:order-1">
                    <div className="max-w-lg">
                        <p className="uppercase tracking-[0.35em] text-[11px] text-gold-700 mb-5">{hero.eyebrow}</p>
                        <h1 className="font-display text-4xl sm:text-5xl lg:text-6xl leading-[1.05] text-ink-900" dangerouslySetInnerHTML={{ __html: hero.headingHtml }} />
                        <p className="mt-6 text-ink-700/70 text-lg leading-relaxed">{hero.subtitle}</p>

                        <div className="mt-8 flex flex-wrap gap-3">
                            <SmartLink href={hero.ctaLink} className="inline-flex items-center gap-2 rounded-full bg-ink-900 text-white px-7 py-3.5 text-sm tracking-wide hover:bg-ink-800 hover:-translate-y-0.5 transition-all duration-300 shadow-lg shadow-ink-900/10">
                                {hero.ctaText}
                                <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24"><path strokeLinecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                            </SmartLink>
                            {/* Gift entry point: scrolls to the finder below. */}
                            <a href="#gift-finder" className="inline-flex items-center gap-2 rounded-full border border-gold-300 bg-gold-50 text-gold-800 px-6 py-3.5 text-sm tracking-wide hover:bg-gold-100 hover:border-gold-400 transition-colors">
                                <Icon name="gift" className="w-4 h-4" /> Shopping for someone?
                            </a>
                        </div>
                        {hero.secondaryText && (
                            <a href={hero.secondaryLink} className={`inline-block mt-4 ${underlineLink}`}>{hero.secondaryText}</a>
                        )}

                        <div className="mt-10 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-ink-700/70">
                            {hasReviews
                                ? <span className="inline-flex items-center gap-1.5"><span className="text-gold-500">★★★★★</span> Loved by customers</span>
                                : <span>Hand-checked before it ships</span>}
                            {trust.map((line, i) => (
                                <span key={i} className="contents">
                                    <span className="hidden sm:inline">·</span>
                                    <span>{line}</span>
                                </span>
                            ))}
                        </div>
                    </div>
                </div>

                <div
                    className="order-1 lg:order-2 relative min-h-[38vh] sm:min-h-[46vh] lg:min-h-[82vh] bg-gold-100 overflow-hidden group"
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
                        if (!mounted.has(k)) return <div key={k} className={cls} aria-hidden="true" />;
                        const inner = s.type === 'video' && s.video?.type === 'file' ? (
                            <video src={s.video.src} autoPlay muted loop playsInline className="w-full h-full object-cover" />
                        ) : s.type === 'video' ? (
                            <iframe src={s.video.embedUrl} title={s.alt} className="w-full h-full pointer-events-none" loading="lazy" allow="autoplay" tabIndex={-1} />
                        ) : (
                            <img
                                src={s.image}
                                alt={s.alt}
                                decoding="async"
                                {...(k > 0 ? { loading: 'lazy' } : { fetchPriority: 'high' })}
                                {...(s.image900 ? { srcSet: `${s.image900} 900w, ${s.image} 1600w`, sizes: '(min-width: 1024px) 50vw, 100vw' } : {})}
                                className="w-full h-full object-cover transition-transform duration-[1200ms] ease-out group-hover:scale-105"
                            />
                        );
                        return s.link ? (
                            <SmartLink key={k} href={s.link} aria-label={s.alt || 'View the collection'} aria-hidden={!active} tabIndex={active ? 0 : -1} className={cls}>{inner}</SmartLink>
                        ) : (
                            <div key={k} aria-hidden={!active} className={cls}>{inner}</div>
                        );
                    })}

                    <div className="absolute inset-0 bg-gradient-to-t from-black/15 via-transparent to-transparent pointer-events-none"></div>

                    {slides.length > 1 && (
                        <>
                            <button type="button" onClick={() => go(i - 1)} aria-label="Previous"
                                className="absolute left-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/80 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">‹</button>
                            <button type="button" onClick={() => go(i + 1)} aria-label="Next"
                                className="absolute right-4 top-1/2 -translate-y-1/2 w-10 h-10 grid place-items-center rounded-full bg-white/80 text-ink-900 shadow-sm opacity-0 group-hover:opacity-100 focus-visible:opacity-100 transition">›</button>
                            <div className="absolute bottom-1 inset-x-0 flex justify-center">
                                {slides.map((_, k) => (
                                    <button key={k} type="button" onClick={() => go(k)} aria-label={`Go to slide ${k + 1}`} className="w-11 h-11 grid place-items-center">
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

/* ── Reassurance strip ──────────────────────────────────────────────────── */
function FeatureStrip({ strip }) {
    if (!strip?.show || !strip.items?.length) return null;
    const cols = { 1: 'lg:grid-cols-1', 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3', 4: 'lg:grid-cols-4' }[strip.items.length] || 'lg:grid-cols-4';
    return (
        <section className="border-y border-ink-100 bg-white">
            <div className={`mx-auto max-w-7xl px-4 grid grid-cols-2 ${cols} divide-x divide-ink-100`}>
                {strip.items.map((f, i) => (
                    <div key={i} className="flex flex-col items-center text-center gap-1.5 py-6 px-3">
                        <IconOrGlyph value={f.icon} fallback="check" className="w-6 h-6 text-gold-700" />
                        <span className="text-[11px] sm:text-xs tracking-wide uppercase text-ink-800 font-medium">{f.title}</span>
                        {f.text && <span className="text-[11px] text-ink-700/70">{f.text}</span>}
                    </div>
                ))}
            </div>
        </section>
    );
}

/* ── Shop by occasion: the gift path ────────────────────────────────────── */
// Typographic tiles when no photo is set — four rotating premium palettes so
// a row of eight still reads as designed, not as placeholders.
const TILE_PALETTES = [
    'bg-gradient-to-br from-ink-900 to-ink-800 text-white',
    'bg-gradient-to-br from-gold-200 via-gold-100 to-white text-ink-900',
    'bg-gradient-to-br from-gold-700 to-gold-900 text-white',
    'bg-gradient-to-br from-white via-gold-50 to-gold-100 text-ink-900',
];

function Occasions({ section }) {
    if (!section?.show || !section.items?.length) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 py-14 lg:py-20">
            <Heading eyebrow="For every moment" title={section.title} subtitle={section.subtitle} center />
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4 lg:gap-5">
                {section.items.map((o, i) => (
                    <SmartLink key={i} href={o.link} className="group block focus:outline-none focus-visible:ring-2 focus-visible:ring-gold-400 rounded-2xl">
                        <div className={`relative aspect-[4/5] overflow-hidden rounded-2xl transition-transform duration-500 group-hover:-translate-y-1 ${o.image ? 'bg-gold-100' : TILE_PALETTES[i % TILE_PALETTES.length]}`}>
                            {o.image ? (
                                <>
                                    <img
                                        src={o.image} alt={o.label} loading="lazy" width="450" height="562"
                                        {...(o.image450 ? { srcSet: `${o.image450} 450w, ${o.image} 1200w`, sizes: '(min-width: 768px) 25vw, 50vw' } : {})}
                                        className="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105"
                                    />
                                    <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/15 to-transparent"></div>
                                    <div className="absolute inset-x-0 bottom-0 p-4">
                                        <h3 className="font-display text-lg text-white leading-tight">{o.label}</h3>
                                        {o.tagline && <p className="text-[11px] text-white/75 mt-0.5 leading-snug">{o.tagline}</p>}
                                    </div>
                                </>
                            ) : (
                                <div className="absolute inset-0 p-4 sm:p-5 flex flex-col justify-between">
                                    <span className="text-[10px] uppercase tracking-[0.3em] opacity-60">Gift idea</span>
                                    <div>
                                        <h3 className="font-display text-xl sm:text-2xl leading-tight">{o.label}</h3>
                                        {o.tagline && <p className="text-[11px] sm:text-xs opacity-70 mt-1 leading-snug">{o.tagline}</p>}
                                        <span className="inline-flex items-center gap-1 mt-3 text-[11px] tracking-wide opacity-80 group-hover:opacity-100 transition">
                                            Explore
                                            <svg aria-hidden="true" className="w-3.5 h-3.5 transition group-hover:translate-x-1" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                                        </span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </SmartLink>
                ))}
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
                    {deals.subtitle && <p className="mt-2 text-ink-700/70">{deals.subtitle}</p>}
                </div>
                {deals.endsAt && (
                    <div className="flex items-center gap-2">
                        <span className="text-xs uppercase tracking-widest text-ink-700/70 mr-1">Ends in</span>
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
                                    <svg aria-hidden="true" className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.878.53 2.31-.354A11.95 11.95 0 0021 15.75c0-.98-.117-1.933-.338-2.846M6.375 6.375h.008v.008H6.375V6.375z" /></svg>
                                    {card.tag}
                                </p>
                                <h3 className="font-semibold text-ink-900 leading-snug">{card.title}</h3>
                                {card.description && <p className="text-sm text-ink-700/70 line-clamp-2">{card.description}</p>}
                            </div>
                            <div className="flex items-center justify-between pt-3 border-t border-ink-100">
                                <span className="text-xs tracking-wide text-ink-700/70">Shop this deal</span>
                                <span className="w-8 h-8 rounded-full bg-ink-50 text-ink-900 grid place-items-center transition duration-300 group-hover:-rotate-45 group-hover:bg-ink-900 group-hover:text-white">
                                    <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="1.8" viewBox="0 0 24 24"><path strokeLinecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                                </span>
                            </div>
                        </div>
                    </SmartLink>
                ))}
            </Carousel>
        </section>
    );
}

/* ── Product grid section (best sellers / new arrivals) ─────────────────── */
function CardSection({ section, eyebrow, viewAll, viewAllLabel, tinted = false }) {
    if (!section?.show || !section.cards?.length) return null;
    return (
        <section className={tinted ? 'bg-gold-50/60 py-16 lg:py-20' : 'py-16 lg:py-20'}>
            <div className="mx-auto max-w-7xl px-4">
                <Heading eyebrow={eyebrow} title={section.title} action={<SmartLink href={viewAll} className={underlineLink}>{viewAllLabel}</SmartLink>} />
                <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 sm:gap-x-5 gap-y-10">
                    {section.cards.map((p) => <ProductCard key={p.id} product={p} />)}
                </div>
                <div className="mt-8 text-center sm:hidden">
                    <SmartLink href={viewAll} className="inline-flex items-center gap-2 rounded-full border border-ink-900/20 px-6 py-3 text-sm tracking-wide">{viewAllLabel} →</SmartLink>
                </div>
            </div>
        </section>
    );
}

/* ── Gift finder: budget bands + the gifting promise ────────────────────── */
function GiftFinder({ finder }) {
    if (!finder?.show || !finder.budgets?.length) return null;
    return (
        <section id="gift-finder" className="bg-ink-900 text-white py-16 lg:py-20 scroll-mt-20">
            <div className="mx-auto max-w-5xl px-4 text-center">
                <p className="uppercase tracking-[0.3em] text-[11px] text-gold-300 mb-3">Gift finder</p>
                <h2 className="font-display text-3xl sm:text-4xl lg:text-5xl leading-tight">{finder.title}</h2>
                {finder.text && <p className="mt-4 text-white/60 max-w-xl mx-auto">{finder.text}</p>}
                <div className="mt-9 flex flex-wrap justify-center gap-3">
                    {finder.budgets.map((b, i) => (
                        <SmartLink key={i} href={b.url} className="rounded-full border border-white/25 px-6 sm:px-8 py-3 text-sm tracking-wide hover:bg-white hover:text-ink-900 hover:border-white transition-colors duration-300">{b.label}</SmartLink>
                    ))}
                </div>
                <div className="mt-10 grid grid-cols-3 gap-4 text-center max-w-2xl mx-auto">
                    {(finder.promises || []).map(({ icon, title, text }) => (
                        <div key={title}>
                            <div className="flex justify-center text-gold-300"><IconOrGlyph value={icon} fallback="gift" className="w-6 h-6" /></div>
                            <div className="mt-1.5 text-sm font-medium">{title}</div>
                            <div className="text-[11px] text-white/50 mt-0.5">{text}</div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ── Category lookbook (first tile large) ─────────────────────────────────── */
function CategoryLookbook({ section }) {
    if (!section?.show || !section.items?.length) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 py-16 lg:py-20">
            <Heading eyebrow="Explore" title={section.title} action={<SmartLink href="/shop" className={underlineLink}>View all</SmartLink>} />
            <div className="grid grid-cols-2 md:grid-cols-3 gap-4 lg:gap-6">
                {section.items.map((cat, i) => (
                    <SmartLink key={cat.url} href={cat.url}
                        className={`group relative block overflow-hidden rounded-2xl bg-gold-100 ${i === 0 ? 'md:col-span-2 md:row-span-2 aspect-[4/3] md:aspect-auto' : 'aspect-square'}`}>
                        {cat.image && <img src={cat.image} alt={cat.name} loading="lazy" className="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105" />}
                        <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/10 to-transparent"></div>
                        <div className="absolute bottom-0 left-0 p-5">
                            <h3 className="font-display text-xl lg:text-2xl text-white">{cat.name}</h3>
                            <span className="text-white/80 text-xs tracking-wide inline-flex items-center gap-1 mt-1">
                                Discover
                                <svg aria-hidden="true" className="w-3.5 h-3.5 transition group-hover:translate-x-1" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                            </span>
                        </div>
                    </SmartLink>
                ))}
            </div>
        </section>
    );
}

function Featured({ featured }) {
    if (!featured?.cards?.length) return null;
    return (
        <section className="bg-gold-50/60 py-16 lg:py-20">
            <div className="mx-auto max-w-7xl px-4">
                <Heading eyebrow="Curated" title={featured.title} subtitle="Our most-loved pieces, chosen for the season." center />
                <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 sm:gap-x-5 gap-y-10">
                    {featured.cards.map((p) => <ProductCard key={p.id} product={p} />)}
                </div>
                <div className="text-center mt-12">
                    <SmartLink href="/shop" className="inline-flex items-center gap-2 rounded-full border border-ink-900/20 px-8 py-3.5 text-sm tracking-wide hover:bg-ink-900 hover:text-white transition-colors duration-300">Shop all jewelry</SmartLink>
                </div>
            </div>
        </section>
    );
}

/* ── How gifting works ────────────────────────────────────────────────────── */
function GiftingSteps({ giftFinder }) {
    const steps = giftFinder?.steps || [];
    if (steps.length === 0) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 py-14 lg:py-16">
            <div className="rounded-3xl border border-gold-200 bg-gradient-to-br from-gold-50 via-white to-gold-50 p-6 sm:p-10">
                <div className="text-center max-w-xl mx-auto mb-8">
                    <p className="uppercase tracking-[0.3em] text-[11px] text-gold-700 mb-2">Gifting, made effortless</p>
                    <h2 className="font-display text-2xl sm:text-3xl text-ink-900">Send it straight to them — we handle the rest</h2>
                </div>
                <ol className="grid grid-cols-2 lg:grid-cols-4 gap-5 sm:gap-6">
                    {steps.map(({ title, text }, n) => (
                        <li key={title} className="flex gap-3">
                            <span className="shrink-0 w-8 h-8 rounded-full bg-ink-900 text-white grid place-items-center text-sm font-semibold">{n + 1}</span>
                            <div>
                                <h3 className="font-medium text-sm">{title}</h3>
                                <p className="text-xs text-ink-700/70 mt-0.5 leading-relaxed">{text}</p>
                            </div>
                        </li>
                    ))}
                </ol>
                <div className="text-center mt-8">
                    {/* Only jump to the finder when it is actually on the page;
                        otherwise send them somewhere real. */}
                    {giftFinder?.show && giftFinder.budgets?.length ? (
                        <a href="#gift-finder" className="inline-flex items-center gap-2 rounded-full bg-gold-600 text-white px-7 py-3 text-sm tracking-wide hover:bg-gold-700 transition-colors">Find a gift by budget</a>
                    ) : (
                        <SmartLink href="/shop" className="inline-flex items-center gap-2 rounded-full bg-gold-600 text-white px-7 py-3 text-sm tracking-wide hover:bg-gold-700 transition-colors">Browse the collection</SmartLink>
                    )}
                </div>
            </div>
        </section>
    );
}

/* ── Customer love (sitewide recent 4–5★ reviews) ────────────────────────── */
function ReviewsBand({ reviews }) {
    if (!reviews?.length) return null;
    return (
        <section className="py-14 bg-white border-y border-ink-100">
            <div className="mx-auto max-w-7xl px-4">
                <Heading eyebrow="Customer love" title="What they're saying" center />
                <Carousel>
                    {reviews.map((t, i) => (
                        <div key={i} className="snap-start shrink-0 w-[300px] rounded-2xl bg-gold-50/60 border border-gold-100 p-6 flex flex-col">
                            <div className="flex text-gold-500 mb-3" aria-label={`${t.rating} star review`}>
                                {[1, 2, 3, 4, 5].map((s) => <Star key={s} className="w-4 h-4" off={s > t.rating} />)}
                            </div>
                            <p className="font-display italic text-ink-800 leading-relaxed flex-1 line-clamp-5">“{t.quote}”</p>
                            <div className="flex items-center gap-3 mt-5">
                                <div className="w-10 h-10 rounded-full bg-gold-100 text-gold-700 text-xs font-semibold flex items-center justify-center shrink-0">
                                    {(t.author || '').trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('')}
                                </div>
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold text-ink-900">{t.author}</p>
                                    {t.product && <SmartLink href={t.product.url} className="text-xs text-ink-700/70 hover:text-gold-700 truncate block">{t.product.name}</SmartLink>}
                                </div>
                            </div>
                        </div>
                    ))}
                </Carousel>
            </div>
        </section>
    );
}

function Promise({ promise }) {
    if (!promise?.show) return null;
    return (
        <section className="mx-auto max-w-7xl px-4 py-16 lg:py-20">
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
                                <p className="text-xs text-ink-700/70 mt-1">{b.text}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </section>
    );
}
