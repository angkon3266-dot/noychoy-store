import { useEffect, useState } from 'react';
import { usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import Carousel from '../Shared/Carousel';
import HomeBlocks from '../Shared/HomeBlocks';
import SmartLink from '../Shared/SmartLink';
import useCountdown from '../Shared/useCountdown';
import Icon, { IconOrGlyph, Star } from '../Shared/Icons';
import { t } from '../Shared/i18n';

// The homepage — editorial, conversion-first and gift-led (redesigned
// 2026-10-24).
//
// Order is deliberate (mobile-first, every step earns the next scroll):
//   hero → reassurance ribbon → categories → picked for you → shop by
//   occasion (the gift path) → deals → membership → best sellers → recently
//   viewed → gift finder by budget → featured edit → how gifting works (off
//   by default) → customer love → our promise → new arrivals → admin builder
//   blocks.
// Every section is admin-toggleable/editable through the same home_content
// settings the old templates used; copy falls back to sensible defaults.
export default function Home(props) {
    const { hero, featureStrip, occasions, deals, bestSellers, giftFinder, categoriesSection, featured, reviews, promise, newArrivals, blocks, heroTrust, pickedForYou, recentlyViewed } = props;
    return (
        <>
            <Hero hero={hero} hasReviews={reviews?.length > 0} trust={heroTrust} giftFinder={giftFinder} />
            <Ribbon strip={featureStrip} trust={heroTrust} />
            <Categories section={categoriesSection} />
            {/* Per-visitor rows: what they told the gift finder, loved or looked
                at. Both hide themselves until there is a real signal. */}
            <CardSection section={pickedForYou} eyebrow="Chosen for you" viewAll="/shop" viewAllLabel="Browse everything" rail />
            <Occasions section={occasions} />
            <Deals deals={deals} />
            <MembershipBand />
            <CardSection section={bestSellers} eyebrow="Most loved" viewAll="/best-sellers" viewAllLabel="All best sellers" />
            <CardSection section={recentlyViewed} eyebrow="Pick up where you left off" viewAll="/shop" viewAllLabel="Keep browsing" rail />
            <GiftFinder finder={giftFinder} />
            <Featured featured={featured} />
            <GiftingSteps giftFinder={giftFinder} />
            <ReviewsBand reviews={reviews} />
            <Promise promise={promise} />
            <CardSection section={newArrivals} eyebrow="Just in" viewAll="/shop?sort=new" viewAllLabel="See what's new" rail />
            <HomeBlocks blocks={blocks} />
        </>
    );
}

Home.layout = (page) => <Layout>{page}</Layout>;

const Arrow = ({ className = 'w-4 h-4' }) => (
    <svg aria-hidden="true" className={className} fill="none" stroke="currentColor" strokeWidth="1.6" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
);

/* ── Shared section heading ─────────────────────────────────────────────── */
function Heading({ eyebrow, title, subtitle = null, action = null, center = false, dark = false }) {
    return (
        <div className={`mb-10 lg:mb-14 ${center ? 'text-center max-w-2xl mx-auto' : 'flex flex-wrap items-end justify-between gap-x-10 gap-y-4'}`}>
            <div className={center ? '' : 'max-w-2xl'}>
                {eyebrow && (
                    <p className={`eyebrow flex items-center gap-3 ${center ? 'justify-center' : ''} ${dark ? '!text-white/60' : ''}`}>
                        <span className={`h-px w-8 ${dark ? 'bg-white/40' : 'bg-ink-900/25'}`} aria-hidden="true" />
                        {eyebrow}
                    </p>
                )}
                <h2 className={`display mt-4 text-[2.1rem] sm:text-5xl lg:text-[3.5rem] ${dark ? '!text-white' : ''}`}>{title}</h2>
                {subtitle && <p className={`mt-4 text-[15px] leading-relaxed ${dark ? 'text-white/65' : 'text-ink-700/75'}`}>{subtitle}</p>}
            </div>
            {action && <div className="shrink-0">{action}</div>}
        </div>
    );
}

function ViewAll({ href, children, dark = false }) {
    return (
        <SmartLink href={href} className={`lux-arrow group/va inline-flex items-center gap-3 text-[12px] font-medium tracking-[0.12em] uppercase ${dark ? 'text-white' : 'text-ink-900'}`}>
            <span className="lux-link lux-link-on">{children}</span>
            <span className={`grid place-items-center w-9 h-9 rounded-full border transition-colors duration-300 ${dark ? 'border-white/30 group-hover/va:bg-white group-hover/va:text-ink-900' : 'border-ink-900/15 group-hover/va:bg-ink-900 group-hover/va:text-white group-hover/va:border-ink-900'}`}>
                <Arrow className="w-3.5 h-3.5" />
            </span>
        </SmartLink>
    );
}

const shell = 'mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-10';

/* ── Hero: editorial split, story-style slideshow ───────────────────────── */
function Hero({ hero, hasReviews = false, trust = [], giftFinder }) {
    const slides = hero.slides || [];
    const [i, setI] = useState(0);
    const [paused, setPaused] = useState(false);
    const [touchX, setTouchX] = useState(null);
    // Arms the slow zoom one frame after mount, so the first slide drifts in
    // like the rest instead of starting already zoomed.
    const [ready, setReady] = useState(false);
    useEffect(() => {
        const raf = requestAnimationFrame(() => setReady(true));
        return () => cancelAnimationFrame(raf);
    }, []);

    // Only the current slide (and the one queued behind it) is put in the
    // DOM. They are all absolutely positioned inside the viewport, so
    // loading="lazy" does nothing — eight hero images would all be fetched at
    // first paint, competing with the LCP image and the JS bundle.
    const [mounted, setMounted] = useState(() => new Set(slides.length > 1 ? [0, 1] : [0]));
    useEffect(() => {
        setMounted((prev) => {
            const next = new Set(prev).add(i).add((i + 1) % slides.length);
            return next.size === prev.size ? prev : next;
        });
    }, [i, slides.length]);

    const go = (k) => setI(((k % slides.length) + slides.length) % slides.length);
    const many = slides.length > 1;
    const current = slides[i] || {};
    const showFinder = giftFinder?.show && giftFinder.budgets?.length;

    // The slide timer is the progress bar's own CSS animation: when it ends,
    // the next slide starts. Pausing the animation pauses the slideshow, and
    // reduced-motion visitors (no animation) simply get no auto-advance.
    const progress = (
        <div className={`flex gap-1.5 ${paused ? 'story-paused' : ''}`} aria-hidden="true">
            {slides.map((_, k) => (
                <span key={k} className="relative h-[3px] flex-1 overflow-hidden rounded-full bg-white/35">
                    {k < i && <span className="absolute inset-0 bg-white" />}
                    {k === i && <span key={`p${i}`} className="story-fill absolute inset-0 bg-white" style={{ '--story-dur': '6.5s' }} onAnimationEnd={() => go(i + 1)} />}
                </span>
            ))}
        </div>
    );

    return (
        <section className="relative pt-3 sm:pt-5 lg:pt-6" aria-label="Featured">
            <div className={`${shell} grid lg:grid-cols-12 gap-7 lg:gap-12 items-stretch`}>
                {/* Copy */}
                <div className="order-2 lg:order-1 lg:col-span-5 flex flex-col justify-between lg:py-6 xl:py-10">
                    <div>
                        <p className="eyebrow flex items-center gap-3 rise-in" style={{ '--d': '.05s' }}>
                            <span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />
                            {hero.eyebrow}
                        </p>
                        <h1 className="display mt-5 lg:mt-7 text-[2.75rem] sm:text-6xl lg:text-[4.6rem] xl:text-[5.4rem] rise-in [&_span]:italic [&_span]:text-gold-600" style={{ '--d': '.12s' }} dangerouslySetInnerHTML={{ __html: hero.headingHtml }} />
                        <p className="mt-5 lg:mt-7 max-w-md text-[15px] sm:text-base lg:text-[17px] leading-relaxed text-ink-700/75 rise-in" style={{ '--d': '.2s' }}>{hero.subtitle}</p>

                        <div className="mt-7 lg:mt-9 flex flex-wrap gap-3 rise-in" style={{ '--d': '.28s' }}>
                            <SmartLink href={hero.ctaLink} className="lux-btn">
                                {hero.ctaText}
                                <Arrow />
                            </SmartLink>
                            {/* Gift entry point: scrolls to the finder below. */}
                            {showFinder ? (
                                <a href="#gift-finder" className="lux-btn-ghost">
                                    <Icon name="gift" className="w-4 h-4" strokeWidth={1.6} /> Shopping for someone?
                                </a>
                            ) : hero.secondaryText ? (
                                <a href={hero.secondaryLink} className="lux-btn-ghost">{hero.secondaryText}</a>
                            ) : null}
                        </div>
                        {showFinder && hero.secondaryText && (
                            <a href={hero.secondaryLink} className="inline-block mt-5 text-[13px] text-ink-700/80 lux-link lux-link-on rise-in" style={{ '--d': '.34s' }}>{hero.secondaryText}</a>
                        )}
                    </div>

                    <div className="mt-9 lg:mt-12 rise-in" style={{ '--d': '.4s' }}>
                        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-[12px] text-ink-700/75 border-t border-ink-900/10 pt-5">
                            {hasReviews
                                ? <span className="inline-flex items-center gap-1.5"><span className="flex text-gold-500">{[1, 2, 3, 4, 5].map((s) => <Star key={s} className="w-3.5 h-3.5" />)}</span> Loved by customers</span>
                                : <span className="inline-flex items-center gap-1.5"><Icon name="shieldCheck" className="w-4 h-4 text-ink-900" strokeWidth={1.5} /> Hand-checked before it ships</span>}
                            {trust.map((line, k) => (
                                <span key={k} className="inline-flex items-center gap-1.5">
                                    <Icon name="check" className="w-3.5 h-3.5 text-ink-900" strokeWidth={2} />
                                    {line}
                                </span>
                            ))}
                        </div>

                        {/* Desktop filmstrip: every slide, the current one lit. */}
                        {many && (
                            <div className="hidden lg:flex mt-7 gap-2.5" role="group" aria-label="Choose a slide">
                                {slides.slice(0, 6).map((s, k) => (
                                    <button
                                        key={k}
                                        type="button"
                                        onClick={() => go(k)}
                                        aria-label={`Show slide ${k + 1}`}
                                        aria-current={i === k}
                                        className={`relative w-14 h-14 rounded-xl overflow-hidden transition duration-500 ${i === k ? 'ring-2 ring-ink-900 ring-offset-2 ring-offset-gold-50' : 'opacity-55 hover:opacity-100'}`}
                                    >
                                        {s.image && <img src={s.image900 || s.image} alt="" loading="lazy" decoding="async" className="w-full h-full object-cover" />}
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* Canvas */}
                <div className="order-1 lg:order-2 lg:col-span-7">
                    <div
                        className="relative aspect-[4/5] sm:aspect-[5/4] lg:aspect-auto lg:h-full lg:min-h-[600px] xl:min-h-[680px] max-h-[860px] overflow-hidden rounded-[26px] lg:rounded-[32px] bg-mist-100 group"
                        onMouseEnter={() => setPaused(true)}
                        onMouseLeave={() => setPaused(false)}
                        onTouchStart={(e) => { setTouchX(e.changedTouches[0].clientX); setPaused(true); }}
                        onTouchEnd={(e) => {
                            if (touchX !== null) {
                                const dx = e.changedTouches[0].clientX - touchX;
                                if (Math.abs(dx) > 40) go(dx < 0 ? i + 1 : i - 1);
                                setTouchX(null);
                            }
                            setPaused(false);
                        }}
                    >
                        {slides.map((s, k) => {
                            const active = slides.length <= 1 || i === k;
                            const cls = `absolute inset-0 block transition-opacity duration-[1200ms] ease-[var(--ease-lux)] ${active ? 'opacity-100' : 'opacity-0 pointer-events-none'}`;
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
                                    {...(s.image900 ? { srcSet: `${s.image900} 900w, ${s.image} 1600w`, sizes: '(min-width: 1024px) 58vw, 100vw' } : {})}
                                    className={`w-full h-full object-cover object-[50%_30%] transition-transform duration-[8000ms] ease-out motion-reduce:transition-none ${active && ready ? 'scale-[1.08] motion-reduce:scale-100' : 'scale-100'}`}
                                />
                            );
                            return s.link ? (
                                <SmartLink key={k} href={s.link} aria-label={s.alt || 'View the collection'} aria-hidden={!active} tabIndex={active ? 0 : -1} className={cls}>{inner}</SmartLink>
                            ) : (
                                <div key={k} aria-hidden={!active} className={cls}>{inner}</div>
                            );
                        })}

                        <div className="absolute inset-0 bg-gradient-to-b from-black/25 via-transparent to-black/35 pointer-events-none" />

                        {many && <div className="absolute top-4 inset-x-4 lg:top-6 lg:inset-x-6">{progress}</div>}

                        {/* Tap zones on phones, Instagram-story style. */}
                        {many && (
                            <>
                                <button type="button" onClick={() => go(i - 1)} aria-label="Previous slide" className="lg:hidden absolute left-0 top-12 bottom-20 w-1/4" />
                                <button type="button" onClick={() => go(i + 1)} aria-label="Next slide" className="lg:hidden absolute right-0 top-12 bottom-20 w-1/4" />
                            </>
                        )}

                        <div className="absolute inset-x-4 bottom-4 lg:inset-x-6 lg:bottom-6 flex items-end justify-between gap-3 pointer-events-none">
                            {current.link ? (
                                <SmartLink href={current.link} tabIndex={-1} aria-hidden="true" className="pointer-events-auto glass-dark inline-flex items-center gap-3 rounded-full pl-4 pr-2 py-2 text-white text-[12px] font-medium tracking-[0.08em] uppercase hover:bg-black/50 transition-colors">
                                    Shop the look
                                    <span className="grid place-items-center w-8 h-8 rounded-full bg-white text-ink-900"><Arrow className="w-3.5 h-3.5" /></span>
                                </SmartLink>
                            ) : <span />}
                            {many && (
                                <div className="pointer-events-auto flex items-center gap-2">
                                    <span className="hidden sm:inline text-white/90 text-[12px] tabular-nums tracking-[0.12em] mr-1">{String(i + 1).padStart(2, '0')} / {String(slides.length).padStart(2, '0')}</span>
                                    <button type="button" onClick={() => go(i - 1)} aria-label="Previous" className="hidden lg:grid place-items-center w-11 h-11 rounded-full glass-dark text-white hover:bg-white hover:text-ink-900 transition-colors">
                                        <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                                    </button>
                                    <button type="button" onClick={() => go(i + 1)} aria-label="Next" className="hidden lg:grid place-items-center w-11 h-11 rounded-full glass-dark text-white hover:bg-white hover:text-ink-900 transition-colors">
                                        <svg aria-hidden="true" className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                                    </button>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ── Reassurance ribbon (a slow ticker) ─────────────────────────────────── */
function Ribbon({ strip, trust = [] }) {
    if (!strip?.show || !strip.items?.length) return null;
    const items = strip.items;
    // Enough copies that one half of the track is wider than any screen.
    const reps = Math.max(2, Math.ceil(8 / items.length));
    const half = Array.from({ length: reps }, () => items).flat();

    const row = (hidden) => (
        <div className="flex shrink-0 items-center" aria-hidden={hidden || undefined}>
            {half.map((f, k) => (
                <span key={k} className="flex items-center gap-3 px-7 sm:px-10 whitespace-nowrap">
                    <IconOrGlyph value={f.icon} fallback="check" className="w-[18px] h-[18px] text-gold-600" />
                    <span className="text-[12px] sm:text-[13px] tracking-[0.18em] uppercase font-medium text-ink-900">{f.title}</span>
                    {f.text && <span className="text-[12px] text-ink-700/60 normal-case tracking-normal">— {f.text}</span>}
                    <span className="ml-7 sm:ml-10 text-ink-900/20" aria-hidden="true">✦</span>
                </span>
            ))}
        </div>
    );

    return (
        <section className="mt-10 lg:mt-14 border-y border-ink-900/[0.07] bg-gold-50" aria-label="Why shop with us">
            <ul className="sr-only">
                {items.map((f, k) => <li key={k}>{f.title}{f.text ? ` — ${f.text}` : ''}</li>)}
                {trust.map((line, k) => <li key={`t${k}`}>{line}</li>)}
            </ul>
            <div className="marquee overflow-hidden py-4 sm:py-5" aria-hidden="true">
                <div className="marquee-track" style={{ '--marquee-dur': `${Math.max(30, half.length * 5)}s` }}>
                    {row(false)}
                    {row(true)}
                </div>
            </div>
        </section>
    );
}

/* ── Categories: tall editorial tiles ───────────────────────────────────── */
function Categories({ section }) {
    if (!section?.show || !section.items?.length) return null;
    const n = section.items.length;
    // Up to six tiles share one row on desktop; more than that stays a
    // swipeable row at every width rather than wrapping an orphan.
    const cols = { 1: 'lg:grid-cols-1', 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3', 4: 'lg:grid-cols-4', 5: 'lg:grid-cols-5', 6: 'lg:grid-cols-6' }[n];
    const layout = cols ? `lg:grid ${cols} lg:overflow-visible` : '';

    return (
        <section className="py-16 lg:py-24">
            <div className={shell}>
                <Heading eyebrow="Explore" title={section.title} action={<ViewAll href="/shop">View all</ViewAll>} />
            </div>
            <div className={`${shell} flex ${layout} gap-3 sm:gap-4 lg:gap-5 overflow-x-auto snap-x snap-mandatory scroll-pl-4 sm:scroll-pl-6 lg:scroll-pl-10 no-scrollbar -mb-2 pb-2`}>
                {section.items.map((cat, k) => (
                    <SmartLink
                        key={cat.url}
                        href={cat.url}
                        className={`group relative block shrink-0 w-[64%] sm:w-[40%] ${cols ? 'lg:w-auto' : 'lg:w-[19%]'} snap-start overflow-hidden rounded-[22px] bg-mist-100 aspect-[3/4]`}
                    >
                        {cat.image && (
                            <img
                                src={cat.image}
                                {...(cat.srcset ? { srcSet: cat.srcset, sizes: '(min-width: 1024px) 22vw, 64vw' } : {})}
                                alt="" loading="lazy" decoding="async"
                                className="absolute inset-0 w-full h-full object-cover transition duration-[1200ms] ease-[var(--ease-lux)] group-hover:scale-[1.06]"
                            />
                        )}
                        <div className="absolute inset-0 bg-gradient-to-t from-black/65 via-black/5 to-transparent" />
                        <span className="absolute top-4 left-4 text-[11px] tracking-[0.2em] text-white/80 tabular-nums">{String(k + 1).padStart(2, '0')}</span>
                        <div className="absolute inset-x-0 bottom-0 p-5 lg:p-6 flex items-end justify-between gap-3">
                            <h3 className="display !text-white text-2xl lg:text-[1.9rem]">{cat.name}</h3>
                            <span className="grid place-items-center w-10 h-10 shrink-0 rounded-full glass-dark text-white transition duration-500 group-hover:bg-white group-hover:text-ink-900 group-hover:-rotate-45">
                                <Arrow className="w-4 h-4" />
                            </span>
                        </div>
                    </SmartLink>
                ))}
                <span className={`shrink-0 w-1 ${cols ? 'lg:hidden' : ''}`} aria-hidden="true" />
            </div>
        </section>
    );
}

/* ── Shop by occasion: the gift path ────────────────────────────────────── */
// Desktop: a typographic index — hovering a line brings its photograph up
// beside it. Phones: a swipeable row of cards. Typographic tiles stand in
// when an occasion has no photo.
const TILE_PALETTES = [
    'bg-ink-900 text-white',
    'bg-mist-100 text-ink-900',
    'bg-gold-600 text-white',
    'bg-mist-200 text-ink-900',
];

function Occasions({ section }) {
    const [hover, setHover] = useState(0);
    if (!section?.show || !section.items?.length) return null;
    const items = section.items;

    return (
        <section className="py-16 lg:py-24 bg-mist-50">
            <div className={shell}>
                <Heading eyebrow="For every moment" title={section.title} subtitle={section.subtitle} />

                {/* Desktop index */}
                <div className="hidden lg:grid grid-cols-12 gap-12 items-start">
                    <ol className="col-span-7 border-t border-ink-900/10">
                        {items.map((o, k) => (
                            <li key={k}>
                                <SmartLink
                                    href={o.link}
                                    onMouseEnter={() => setHover(k)}
                                    onFocus={() => setHover(k)}
                                    className="group flex items-center gap-8 py-6 border-b border-ink-900/10"
                                >
                                    <span className="w-10 text-[12px] tabular-nums tracking-[0.18em] text-ink-700/50">{String(k + 1).padStart(2, '0')}</span>
                                    <span className="flex-1 min-w-0">
                                        <span className={`display block text-[2.6rem] xl:text-[3.1rem] transition-[color,transform] duration-500 ease-[var(--ease-lux)] ${hover === k ? 'translate-x-3 !text-ink-900' : '!text-ink-900/35 group-hover:!text-ink-900'}`}>{o.label}</span>
                                        {o.tagline && <span className={`block mt-1 text-sm text-ink-700/70 transition-[opacity,transform] duration-500 ${hover === k ? 'opacity-100 translate-x-3' : 'opacity-0'}`}>{o.tagline}</span>}
                                    </span>
                                    <span className={`grid place-items-center w-12 h-12 rounded-full border transition duration-500 ${hover === k ? 'bg-ink-900 border-ink-900 text-white -rotate-45' : 'border-ink-900/15 text-ink-900'}`}>
                                        <Arrow />
                                    </span>
                                </SmartLink>
                            </li>
                        ))}
                    </ol>
                    <div className="col-span-5 sticky top-28">
                        <div className="relative aspect-[4/5] overflow-hidden rounded-[28px] bg-mist-200">
                            {items.map((o, k) => (
                                o.image ? (
                                    <img
                                        key={k}
                                        src={o.image}
                                        alt=""
                                        loading="lazy"
                                        decoding="async"
                                        className={`absolute inset-0 w-full h-full object-cover transition duration-[900ms] ease-[var(--ease-lux)] ${hover === k ? 'opacity-100 scale-100' : 'opacity-0 scale-[1.06]'}`}
                                    />
                                ) : (
                                    <div key={k} className={`absolute inset-0 grid place-items-center p-10 text-center transition-opacity duration-700 ${TILE_PALETTES[k % TILE_PALETTES.length]} ${hover === k ? 'opacity-100' : 'opacity-0'}`}>
                                        <span className="display !text-current text-5xl">{o.label}</span>
                                    </div>
                                )
                            ))}
                            <div className="absolute inset-x-0 bottom-0 p-6 bg-gradient-to-t from-black/50 to-transparent">
                                <p className="text-white/90 text-[12px] tracking-[0.2em] uppercase">Gift idea · {items[hover]?.label}</p>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Phones + tablets: swipe row */}
                <div className="lg:hidden -mx-4 sm:-mx-6 px-4 sm:px-6 scroll-pl-4 sm:scroll-pl-6 flex gap-3 overflow-x-auto snap-x snap-mandatory no-scrollbar pb-2">
                    {items.map((o, k) => (
                        <SmartLink key={k} href={o.link} className="group relative shrink-0 w-[62%] sm:w-[38%] snap-start">
                            <div className={`relative aspect-[4/5] overflow-hidden rounded-[22px] ${o.image ? 'bg-mist-200' : TILE_PALETTES[k % TILE_PALETTES.length]}`}>
                                {o.image ? (
                                    <>
                                        <img
                                            src={o.image} alt="" loading="lazy" width="450" height="562"
                                            {...(o.image450 ? { srcSet: `${o.image450} 450w, ${o.image} 1200w`, sizes: '62vw' } : {})}
                                            className="absolute inset-0 w-full h-full object-cover"
                                        />
                                        <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent" />
                                    </>
                                ) : null}
                                <div className="absolute inset-x-0 bottom-0 p-4">
                                    <span className={`text-[10px] tracking-[0.2em] ${o.image ? 'text-white/70' : 'opacity-60'}`}>{String(k + 1).padStart(2, '0')}</span>
                                    <h3 className={`display text-[1.6rem] mt-1 ${o.image ? '!text-white' : '!text-current'}`}>{o.label}</h3>
                                    {o.tagline && <p className={`text-[12px] mt-1 leading-snug ${o.image ? 'text-white/75' : 'opacity-70'}`}>{o.tagline}</p>}
                                </div>
                            </div>
                        </SmartLink>
                    ))}
                    <span className="shrink-0 w-1" aria-hidden="true" />
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
        <section className="py-16 lg:py-24">
            <div className={shell}>
                <div className="flex flex-wrap items-end justify-between gap-6 mb-10 lg:mb-14">
                    <div>
                        <p className="eyebrow flex items-center gap-3"><span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />Today only</p>
                        <h2 className="display mt-4 text-[2.1rem] sm:text-5xl lg:text-[3.5rem]">{deals.title}</h2>
                        {deals.subtitle && <p className="mt-3 text-ink-700/75">{deals.subtitle}</p>}
                    </div>
                    {deals.endsAt && (
                        <div className="flex items-center gap-2" role="timer" aria-label="Time left">
                            {units.map((u, k) => (
                                <div key={u.label} className="flex items-center gap-2">
                                    <div className="rounded-2xl bg-ink-900 text-white w-16 py-2.5 text-center">
                                        <div className="text-xl font-semibold tabular-nums tracking-tight">{String(u.value).padStart(2, '0')}</div>
                                        <div className="text-[9px] uppercase tracking-[0.2em] text-white/50 mt-0.5">{u.label}</div>
                                    </div>
                                    {k < units.length - 1 && <span className="text-ink-900/30 text-lg">:</span>}
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <Carousel>
                    {deals.cards.map((card, k) => (
                        <SmartLink key={k} href={card.href}
                            className="group snap-start shrink-0 w-[78%] sm:w-[44%] lg:w-[31.5%] rounded-[22px] overflow-hidden bg-white border border-ink-900/[0.07] flex flex-col transition duration-500 hover:shadow-[0_30px_60px_-35px_rgb(0_0_0/0.4)]">
                            <div className="relative aspect-[16/11] bg-mist-100 overflow-hidden shrink-0">
                                {card.image && <img src={card.image} alt="" loading="lazy" className="w-full h-full object-cover transition-transform duration-[1200ms] ease-[var(--ease-lux)] group-hover:scale-105" />}
                                {card.discount && <span className="absolute top-3 left-3 rounded-full bg-gold-500 text-ink-900 text-[11px] font-semibold px-3 py-1">{card.discount}</span>}
                            </div>
                            <div className="flex-1 p-6 flex flex-col justify-between gap-5">
                                <div className="space-y-2">
                                    <p className="eyebrow">{card.tag}</p>
                                    <h3 className="font-display text-xl text-ink-900 leading-snug">{card.title}</h3>
                                    {card.description && <p className="text-sm text-ink-700/70 line-clamp-2">{card.description}</p>}
                                </div>
                                <div className="flex items-center justify-between pt-4 border-t border-ink-900/[0.07]">
                                    <span className="text-[12px] tracking-[0.12em] uppercase text-ink-900">Shop this deal</span>
                                    <span className="w-9 h-9 rounded-full border border-ink-900/15 grid place-items-center transition duration-500 group-hover:-rotate-45 group-hover:bg-ink-900 group-hover:text-white group-hover:border-ink-900">
                                        <Arrow className="w-3.5 h-3.5" />
                                    </span>
                                </div>
                            </div>
                        </SmartLink>
                    ))}
                </Carousel>
            </div>
        </section>
    );
}

/* ── Membership: what joining is worth ──────────────────────────────────── */
// The store had member prices, points and tiers, and the home page never
// mentioned any of it. Facts come from chrome.membership, the same source
// the header, cart and checkout nudges read, so the numbers cannot drift.
function MembershipBand() {
    const { props } = usePage();
    const m = props.chrome?.membership;
    const urls = props.chrome?.urls || {};
    const lang = props.chrome?.lang || 'en';
    if (!m || (!m.pct && !m.pointsPer1000)) return null;

    const topTier = m.tiers?.length ? m.tiers[m.tiers.length - 1] : null;
    const tiles = [
        m.pct ? { icon: 'tag', title: `${m.pct}% member price`, text: 'On every piece, applied at checkout' } : null,
        m.pointsPer1000 ? { icon: 'medal', title: `${m.pointsPer1000} points per ৳1,000`, text: 'Redeem points for taka off your next order' } : null,
        topTier ? { icon: 'diamond', title: `${m.tiers.map((x) => x.label).join(' · ')}`, text: topTier.perk } : null,
    ].filter(Boolean);

    return (
        <section className="py-10 lg:py-14" aria-labelledby="membership-heading">
            <div className={shell}>
                <div className="grain relative overflow-hidden rounded-[32px] bg-ink-900 text-white px-6 py-10 sm:px-10 lg:px-16 lg:py-16 flex flex-col lg:flex-row lg:items-center gap-10">
                    <div className="pointer-events-none absolute -top-40 -right-24 w-[520px] h-[520px] rounded-full opacity-40 blur-3xl" style={{ background: 'radial-gradient(circle, var(--color-gold-500), transparent 65%)' }} aria-hidden="true" />
                    <div className="relative flex-1 min-w-0">
                        <p className="eyebrow !text-white/60" lang={lang}>{t(lang, m.isMember ? 'band.eyebrow.member' : 'band.eyebrow.guest')}</p>
                        <h2 id="membership-heading" className="display !text-white mt-4 text-4xl sm:text-5xl" lang={lang}>
                            {m.pct ? <>{t(lang, 'band.title')} <em className="text-gold-400">{m.pct}%</em> {t(lang, 'band.title.tail')}</> : t(lang, 'band.title.nopct')}
                        </h2>
                        <p className="mt-4 text-white/65 max-w-xl">{m.text || m.pitch}</p>
                        <SmartLink href={m.isMember ? urls.account : urls.register} className="lux-btn-light mt-8" lang={lang}>
                            {t(lang, m.isMember ? 'band.cta.member' : 'band.cta.guest')}
                            <Arrow />
                        </SmartLink>
                    </div>
                    <ul className="relative grid sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3 gap-3 lg:w-[50%]">
                        {tiles.map((x) => (
                            <li key={x.title} className="rounded-2xl bg-white/[0.06] border border-white/10 p-5">
                                <Icon name={x.icon} className="w-5 h-5 text-gold-400" />
                                <p className="mt-3 font-semibold text-sm">{x.title}</p>
                                <p className="mt-1 text-xs text-white/55 leading-relaxed">{x.text}</p>
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </section>
    );
}

/* ── Product rows (best sellers / new arrivals / per-visitor rows) ───────── */
// `rail` lays the cards out as a swipeable row instead of a grid.
function CardSection({ section, eyebrow, viewAll, viewAllLabel, rail = false }) {
    if (!section?.show || !section.cards?.length) return null;
    return (
        <section className="py-16 lg:py-24">
            <div className={shell}>
                <Heading eyebrow={eyebrow} title={section.title} action={<ViewAll href={viewAll}>{viewAllLabel}</ViewAll>} />
                {rail ? (
                    <Carousel>
                        {section.cards.map((p) => (
                            <div key={p.id} className="snap-start shrink-0 w-[46%] sm:w-[31%] lg:w-[23.4%]"><ProductCard product={p} /></div>
                        ))}
                    </Carousel>
                ) : (
                    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-x-3 sm:gap-x-5 gap-y-10 lg:gap-y-14">
                        {section.cards.map((p) => <ProductCard key={p.id} product={p} />)}
                    </div>
                )}
            </div>
        </section>
    );
}

/* ── Gift finder: occasion + budget, on a dark aurora panel ─────────────── */
function GiftFinder({ finder }) {
    // The occasion — remembered by the shop (session, and the account for
    // members) so "Picked for you", pushes and the assistant can use it.
    const [occasion, setOccasion] = useState(finder?.profile?.occasion || '');
    if (!finder?.show || !finder.budgets?.length) return null;

    const withProfile = (url) => {
        const extra = ['gift=1', occasion ? `occasion=${occasion}` : null].filter(Boolean).join('&');
        return url + (url.includes('?') ? '&' : '?') + extra;
    };
    const chip = (on) => `rounded-full px-4 h-10 text-[13px] border transition-colors duration-300 ${on ? 'bg-white text-ink-900 border-white' : 'border-white/20 text-white/80 hover:border-white/60 hover:text-white'}`;

    return (
        <section id="gift-finder" className="py-10 lg:py-14 scroll-mt-28">
            <div className={shell}>
                <div className="grain relative overflow-hidden rounded-[32px] bg-ink-900 text-white px-6 py-12 sm:px-10 lg:px-16 lg:py-20">
                    <div className="pointer-events-none absolute -top-48 -left-40 w-[640px] h-[640px] rounded-full opacity-60 blur-3xl" style={{ background: 'radial-gradient(circle, var(--color-accent), transparent 62%)' }} aria-hidden="true" />
                    <div className="pointer-events-none absolute -bottom-56 -right-40 w-[560px] h-[560px] rounded-full opacity-35 blur-3xl" style={{ background: 'radial-gradient(circle, var(--color-gold-500), transparent 62%)' }} aria-hidden="true" />

                    <div className="relative grid lg:grid-cols-12 gap-12 lg:gap-16 items-center">
                        <div className="lg:col-span-5">
                            <p className="eyebrow !text-white/60 flex items-center gap-3"><span className="h-px w-8 bg-white/40" aria-hidden="true" />Gift finder</p>
                            <h2 className="display !text-white mt-4 text-[2.4rem] sm:text-5xl lg:text-6xl">{finder.title}</h2>
                            {finder.text && <p className="mt-5 text-white/65 leading-relaxed max-w-md">{finder.text}</p>}
                            {(finder.promises || []).length > 0 && (
                                <ul className="mt-9 space-y-4">
                                    {finder.promises.map(({ icon, title, text }) => (
                                        <li key={title} className="flex items-start gap-4">
                                            <span className="grid place-items-center w-10 h-10 shrink-0 rounded-full bg-white/[0.08] border border-white/10 text-gold-400"><IconOrGlyph value={icon} fallback="gift" className="w-[18px] h-[18px]" /></span>
                                            <span>
                                                <span className="block text-sm font-medium">{title}</span>
                                                <span className="block text-[12px] text-white/50 mt-0.5">{text}</span>
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div className="lg:col-span-7">
                            <div className="rounded-[26px] bg-white/[0.05] border border-white/10 p-6 sm:p-8 backdrop-blur">
                                {finder.occasions?.length > 0 && (
                                    <>
                                        <p className="text-[11px] uppercase tracking-[0.24em] text-white/50"><span className="text-white/90 tabular-nums mr-2">01</span>What's the occasion?</p>
                                        <div className="mt-4 flex flex-wrap gap-2" role="group" aria-label="What is the occasion?">
                                            {finder.occasions.map(([key, label]) => (
                                                <button key={key} type="button" onClick={() => setOccasion(occasion === key ? '' : key)} className={chip(occasion === key)} aria-pressed={occasion === key}>{label}</button>
                                            ))}
                                        </div>
                                        <div className="my-7 h-px bg-white/10" />
                                    </>
                                )}
                                <p className="text-[11px] uppercase tracking-[0.24em] text-white/50"><span className="text-white/90 tabular-nums mr-2">{finder.occasions?.length ? '02' : '01'}</span>Pick a budget</p>
                                <div className="mt-4 grid sm:grid-cols-3 gap-3">
                                    {finder.budgets.map((b, k) => (
                                        <SmartLink key={k} href={withProfile(b.url)} className="group flex sm:flex-col items-center sm:items-start justify-between gap-4 rounded-2xl border border-white/15 px-5 py-4 sm:py-6 hover:bg-white hover:text-ink-900 hover:border-white transition-colors duration-300">
                                            <span className="font-display text-xl sm:text-2xl tracking-[-0.01em]">{b.label}</span>
                                            <span className="grid place-items-center w-9 h-9 rounded-full border border-white/25 group-hover:border-ink-900/20 transition duration-500 group-hover:-rotate-45"><Arrow className="w-3.5 h-3.5" /></span>
                                        </SmartLink>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ── Featured edit: one hero piece + the rest ───────────────────────────── */
function Featured({ featured }) {
    if (!featured?.cards?.length) return null;
    const [lead, ...rest] = featured.cards;

    return (
        <section className="py-16 lg:py-24">
            <div className={shell}>
                <Heading eyebrow="Curated" title={featured.title} subtitle="Our most-loved pieces, chosen for the season." action={<ViewAll href="/shop">Shop all jewelry</ViewAll>} />
                <div className="grid lg:grid-cols-12 gap-x-5 gap-y-10">
                    <SmartLink href={lead.url} className="group lg:col-span-5 relative block overflow-hidden rounded-[28px] bg-mist-100 aspect-[4/5] lg:aspect-auto lg:min-h-full">
                        {lead.thumb && (
                            <img
                                src={lead.thumb} alt="" loading="lazy" decoding="async"
                                {...(lead.srcset ? { srcSet: lead.srcset, sizes: '(min-width: 1024px) 40vw, 100vw' } : {})}
                                className="absolute inset-0 w-full h-full object-cover transition duration-[1400ms] ease-[var(--ease-lux)] group-hover:scale-105"
                            />
                        )}
                        <div className="absolute inset-0 bg-gradient-to-t from-black/70 via-black/0 to-transparent" />
                        <div className="absolute inset-x-0 bottom-0 p-6 lg:p-8 text-white">
                            <p className="text-[11px] tracking-[0.24em] uppercase text-white/70">The piece of the season</p>
                            <h3 className="display !text-white text-3xl lg:text-[2.6rem] mt-3">{lead.name}</h3>
                            <div className="mt-5 flex items-center justify-between gap-4">
                                <span className="text-lg font-semibold">
                                    {lead.price_text}
                                    {lead.on_sale && <span className="ml-2 text-sm font-normal text-white/60 line-through">{lead.compare_text}</span>}
                                </span>
                                <span className="lux-btn-light !min-h-11 !px-5">Discover <Arrow /></span>
                            </div>
                        </div>
                    </SmartLink>
                    <div className="lg:col-span-7 grid grid-cols-2 md:grid-cols-3 gap-x-3 sm:gap-x-5 gap-y-10">
                        {rest.slice(0, 6).map((p) => <ProductCard key={p.id} product={p} />)}
                    </div>
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
        <section className="py-16 lg:py-20 bg-mist-50">
            <div className={shell}>
                <Heading eyebrow="Gifting, made effortless" title="Send it straight to them — we handle the rest" center />
                <ol className="grid grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-10">
                    {steps.map(({ title, text }, n) => (
                        <li key={title} className="relative pt-6 border-t border-ink-900/15">
                            <span className="display text-5xl !text-ink-900/15">{String(n + 1).padStart(2, '0')}</span>
                            <h3 className="mt-3 font-medium text-ink-900">{title}</h3>
                            <p className="text-sm text-ink-700/70 mt-1.5 leading-relaxed">{text}</p>
                        </li>
                    ))}
                </ol>
                <div className="text-center mt-12">
                    {/* Only jump to the finder when it is actually on the page;
                        otherwise send them somewhere real. */}
                    {giftFinder?.show && giftFinder.budgets?.length ? (
                        <a href="#gift-finder" className="lux-btn">Find a gift by budget <Arrow /></a>
                    ) : (
                        <SmartLink href="/shop" className="lux-btn">Browse the collection <Arrow /></SmartLink>
                    )}
                </div>
            </div>
        </section>
    );
}

/* ── Customer love (sitewide recent 4–5★ reviews) ────────────────────────── */
function ReviewsBand({ reviews }) {
    if (!reviews?.length) return null;
    const avg = reviews.reduce((s, r) => s + (Number(r.rating) || 0), 0) / reviews.length;

    return (
        <section className="py-16 lg:py-24 bg-mist-50 overflow-hidden">
            <div className={shell}>
                <div className="grid lg:grid-cols-12 gap-10 lg:gap-14">
                    <div className="lg:col-span-4">
                        <p className="eyebrow flex items-center gap-3"><span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />Customer love</p>
                        <h2 className="display mt-4 text-[2.1rem] sm:text-5xl lg:text-[3.5rem]">What they're saying</h2>
                        <div className="mt-8 flex items-center gap-4">
                            <span className="display text-6xl">{avg.toFixed(1)}</span>
                            <span>
                                <span className="flex text-gold-500">{[1, 2, 3, 4, 5].map((s) => <Star key={s} className="w-4 h-4" off={s > Math.round(avg)} />)}</span>
                                <span className="block text-[12px] text-ink-700/70 mt-1">From customers across Bangladesh</span>
                            </span>
                        </div>
                    </div>
                    <div className="lg:col-span-8 min-w-0">
                        <Carousel>
                            {reviews.map((r, k) => (
                                <figure key={k} className="snap-start shrink-0 w-[86%] sm:w-[48%] rounded-[24px] bg-white p-7 lg:p-8 flex flex-col shadow-[0_1px_2px_rgb(0_0_0/0.04)]">
                                    <div className="flex text-gold-500" aria-label={`${r.rating} star review`}>
                                        {[1, 2, 3, 4, 5].map((s) => <Star key={s} className="w-4 h-4" off={s > r.rating} />)}
                                    </div>
                                    <blockquote className="mt-5 flex-1 font-display text-[1.3rem] leading-snug tracking-[-0.01em] text-ink-900 line-clamp-6">“{r.quote}”</blockquote>
                                    <figcaption className="flex items-center gap-3 mt-7 pt-5 border-t border-ink-900/[0.07]">
                                        <span className="w-10 h-10 rounded-full bg-ink-900 text-white text-xs font-semibold grid place-items-center shrink-0">
                                            {(r.author || '').trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('')}
                                        </span>
                                        <span className="min-w-0">
                                            <span className="block text-sm font-semibold text-ink-900">{r.author}</span>
                                            {r.product && <SmartLink href={r.product.url} className="block text-xs text-ink-700/70 hover:text-ink-900 truncate">{r.product.name}</SmartLink>}
                                        </span>
                                    </figcaption>
                                </figure>
                            ))}
                        </Carousel>
                    </div>
                </div>
            </div>
        </section>
    );
}

/* ── Our promise ────────────────────────────────────────────────────────── */
function Promise({ promise }) {
    if (!promise?.show) return null;
    return (
        <section className="py-16 lg:py-24">
            <div className={shell}>
                <div className="grid lg:grid-cols-12 gap-10 lg:gap-16 items-center">
                    <div className="lg:col-span-6 relative">
                        <div className="relative aspect-[4/5] sm:aspect-[5/4] lg:aspect-[4/5] rounded-[28px] overflow-hidden bg-mist-100">
                            {promise.image && (
                                <img
                                    src={promise.image}
                                    {...(promise.imageSrcset ? { srcSet: promise.imageSrcset, sizes: '(min-width: 1024px) 50vw, 100vw' } : {})}
                                    alt="" className="w-full h-full object-cover" loading="lazy" decoding="async"
                                />
                            )}
                        </div>
                        {/* A slowly turning seal over the photo's corner. */}
                        <div className="hidden sm:grid absolute -bottom-8 -right-6 lg:-right-10 w-36 h-36 place-items-center rounded-full bg-gold-50 shadow-[0_20px_50px_-25px_rgb(0_0_0/0.4)]" aria-hidden="true">
                            <svg viewBox="0 0 100 100" className="absolute inset-2 slow-spin text-ink-900">
                                <defs><path id="seal" d="M50,50 m-38,0 a38,38 0 1,1 76,0 a38,38 0 1,1 -76,0" /></defs>
                                <text fontSize="8.6" fill="currentColor" fontFamily="inherit"><textPath href="#seal" textLength="236" lengthAdjust="spacing">HANDPICKED · CHECKED · DELIVERED ·</textPath></text>
                            </svg>
                            <Icon name="diamond" className="w-7 h-7 text-gold-600" strokeWidth={1.3} />
                        </div>
                    </div>
                    <div className="lg:col-span-6 lg:pl-6">
                        <p className="eyebrow flex items-center gap-3"><span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />{promise.eyebrow}</p>
                        <h2 className="display mt-4 text-[2.4rem] sm:text-5xl lg:text-6xl">{promise.title}</h2>
                        <p className="mt-6 text-ink-700/75 text-[16px] leading-relaxed max-w-lg">{promise.text}</p>
                        <dl className="mt-10 grid grid-cols-3 divide-x divide-ink-900/10 border-y border-ink-900/10">
                            {promise.badges.map((b, k) => (
                                <div key={k} className="py-6 px-3 sm:px-5 first:pl-0">
                                    <dt className="font-display text-lg sm:text-xl text-ink-900 leading-tight">{b.title}</dt>
                                    <dd className="text-[12px] text-ink-700/65 mt-1.5">{b.text}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                </div>
            </div>
        </section>
    );
}
