import { useEffect, useRef, useState } from 'react';
import ProductCard from './ProductCard';
import Carousel from './Carousel';
import SmartLink from './SmartLink';
import { IconOrGlyph, Star } from './Icons';
import { Arrow, Heading, ViewAll, shell } from './HomeKit';

// The "Editorial" homepage design (owner, 2026-10-28: "a more advanced, more
// editorial homepage template"). Same payload, same builder and same section
// order as Couture — Home.jsx swaps these in for the sections they restyle —
// laid out like a magazine: a full-bleed cover, outlined display type, an
// index of categories, numbered chapters, an editor's spread, a sentence for
// a gift finder and a single pull-quote at a time.

const pad = (n) => String(n).padStart(2, '0');
const ROMAN = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

/* ── Cover: full-bleed slideshow with a masthead headline ───────────────── */
export function EdHero({ hero, trust = [], giftFinder }) {
    const slides = hero.slides || [];
    const [i, setI] = useState(0);
    const [paused, setPaused] = useState(false);
    const [soundOn, setSoundOn] = useState(false);
    const [mounted, setMounted] = useState(() => new Set(slides.length > 1 ? [0, 1] : [0]));
    const frames = useRef({});
    const touchX = useRef(null);
    const many = slides.length > 1;
    const current = slides[i] || {};
    const go = (k) => setI(((k % slides.length) + slides.length) % slides.length);
    const edition = new Date().toLocaleString('en', { month: 'long', year: 'numeric' });
    const showFinder = giftFinder?.show && giftFinder.budgets?.length;

    useEffect(() => {
        setMounted((prev) => {
            const next = new Set(prev).add(i).add((i + 1) % Math.max(1, slides.length));
            return next.size === prev.size ? prev : next;
        });
        setSoundOn(false);
    }, [i, slides.length]);

    // Same sound control as Couture: videos start muted, the button turns the
    // current one up, and changing slide mutes everything again.
    useEffect(() => {
        Object.entries(frames.current).forEach(([k, frame]) => {
            if (!frame?.contentWindow) return;
            const on = soundOn && Number(k) === i;
            const msg = slides[k]?.video?.type === 'youtube'
                ? [{ event: 'command', func: on ? 'unMute' : 'mute', args: [] }]
                : [{ method: 'setVolume', value: on ? 1 : 0 }];
            msg.forEach((m) => frame.contentWindow.postMessage(JSON.stringify(m), '*'));
        });
    }, [soundOn, i]);

    return (
        <section
            className="relative isolate h-[86svh] min-h-[560px] max-h-[980px] overflow-hidden bg-ink-900 text-white"
            aria-label="Featured"
            onMouseEnter={() => setPaused(true)}
            onMouseLeave={() => setPaused(false)}
            onTouchStart={(e) => { touchX.current = e.changedTouches[0].clientX; }}
            onTouchEnd={(e) => {
                if (touchX.current === null) return;
                const dx = e.changedTouches[0].clientX - touchX.current;
                if (Math.abs(dx) > 40) go(dx < 0 ? i + 1 : i - 1);
                touchX.current = null;
            }}
        >
            {slides.map((s, k) => {
                const active = slides.length <= 1 || i === k;
                const cls = `absolute inset-0 transition-opacity duration-[1400ms] ease-[var(--ease-lux)] ${active ? 'opacity-100' : 'opacity-0'}`;
                if (!mounted.has(k)) return <div key={k} className={cls} aria-hidden="true" />;
                return (
                    <div key={k} className={cls} aria-hidden={!active}>
                        {s.type === 'video' && s.video?.type === 'file' ? (
                            <video src={s.video.src} autoPlay muted={!(soundOn && active)} loop playsInline className="w-full h-full object-cover" />
                        ) : s.type === 'video' ? (
                            <div className="absolute inset-0 overflow-hidden [container-type:size]">
                                <iframe ref={(el) => { frames.current[k] = el; }} src={s.video.embedUrl} title={s.alt || 'Video'} tabIndex={-1}
                                    {...(k > 0 ? { loading: 'lazy' } : {})} allow="autoplay; encrypted-media; picture-in-picture"
                                    className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 w-[max(100cqw,177.78cqh)] h-[max(100cqh,56.25cqw)] pointer-events-none" />
                            </div>
                        ) : (
                            <img src={s.image} alt={s.alt} decoding="async" {...(k > 0 ? { loading: 'lazy' } : { fetchPriority: 'high' })}
                                {...(s.image900 ? { srcSet: `${s.image900} 900w, ${s.image} 1600w`, sizes: '100vw' } : {})}
                                className={`w-full h-full object-cover object-[50%_30%] transition-transform duration-[9000ms] ease-out motion-reduce:transition-none ${active ? 'scale-[1.06]' : 'scale-100'}`} />
                        )}
                    </div>
                );
            })}
            <div className="absolute inset-0 bg-gradient-to-t from-black/80 via-black/15 to-black/45 pointer-events-none" />

            {/* Masthead line */}
            <div className={`${shell} relative z-10 pt-6 lg:pt-8 flex items-center justify-between text-[11px] tracking-[0.3em] uppercase text-white/80`}>
                <span className="flex items-center gap-3"><span className="h-px w-8 bg-white/50" aria-hidden="true" />{hero.eyebrow}</span>
                <span className="hidden sm:inline">The {edition} edit</span>
                {many && <span className="tabular-nums">{pad(i + 1)} — {pad(slides.length)}</span>}
            </div>

            {/* Cover lines */}
            <div className={`${shell} absolute inset-x-0 bottom-0 z-10 pb-10 lg:pb-14`}>
                <div className="grid lg:grid-cols-12 gap-8 items-end">
                    <h1 className="lg:col-span-8 font-display font-normal leading-[0.92] tracking-[-0.035em] text-[3.1rem] sm:text-7xl lg:text-[7.2rem] xl:text-[8.4rem] rise-in [&_span]:italic [&_span]:text-gold-400"
                        style={{ '--d': '.1s' }} dangerouslySetInnerHTML={{ __html: hero.headingHtml }} />
                    <div className="lg:col-span-4 lg:pb-3 rise-in" style={{ '--d': '.25s' }}>
                        <p className="text-[15px] lg:text-base leading-relaxed text-white/80 max-w-sm">{hero.subtitle}</p>
                        <div className="mt-6 flex flex-wrap gap-3">
                            <SmartLink href={hero.ctaLink} className="lux-btn-light">{hero.ctaText} <Arrow /></SmartLink>
                            {showFinder ? (
                                <a href="#gift-finder" className="lux-btn border border-white/40 !bg-transparent !shadow-none hover:!bg-white/10">{hero.finderLabel || 'Shopping for someone?'}</a>
                            ) : null}
                        </div>
                        {trust.length > 0 && (
                            <p className="mt-6 text-[12px] tracking-[0.08em] text-white/60">{trust.join('  ·  ')}</p>
                        )}
                    </div>
                </div>

                <div className="mt-10 flex items-center gap-4">
                    {many && (
                        <div className={`flex-1 flex gap-1.5 ${paused ? 'story-paused' : ''}`} aria-hidden="true">
                            {slides.map((_, k) => (
                                <span key={k} className="relative h-[2px] flex-1 overflow-hidden bg-white/25">
                                    {k < i && <span className="absolute inset-0 bg-white" />}
                                    {k === i && <span key={`p${i}`} className="story-fill absolute inset-0 bg-white" style={{ '--story-dur': `${slides[i]?.seconds || 6.5}s` }} onAnimationEnd={() => go(i + 1)} />}
                                </span>
                            ))}
                        </div>
                    )}
                    {current.type === 'video' && current.sound && (
                        <button type="button" onClick={() => setSoundOn(!soundOn)} aria-pressed={soundOn} aria-label={soundOn ? 'Turn sound off' : 'Turn sound on'}
                            className="grid place-items-center w-10 h-10 rounded-full glass-dark hover:bg-white hover:text-ink-900 transition-colors">
                            <svg aria-hidden="true" className="w-[18px] h-[18px]" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" viewBox="0 0 24 24">
                                <path d="M11 5L6 9H3v6h3l5 4V5z" />{soundOn ? <path d="M15.5 8.5a5 5 0 010 7M18.5 5.5a9 9 0 010 13" /> : <path d="M16 9l5 6M21 9l-5 6" />}
                            </svg>
                        </button>
                    )}
                    {current.link && (
                        <SmartLink href={current.link} className="shrink-0 inline-flex items-center gap-2 text-[11px] tracking-[0.24em] uppercase text-white/85 hover:text-white">
                            <span className="lux-link lux-link-on">Shop the look</span> <Arrow className="w-3.5 h-3.5" />
                        </SmartLink>
                    )}
                    {many && (
                        <div className="hidden lg:flex gap-2">
                            <button type="button" onClick={() => go(i - 1)} aria-label="Previous" className="grid place-items-center w-10 h-10 rounded-full border border-white/30 hover:bg-white hover:text-ink-900 transition-colors">
                                <Arrow className="w-4 h-4 rotate-180" />
                            </button>
                            <button type="button" onClick={() => go(i + 1)} aria-label="Next" className="grid place-items-center w-10 h-10 rounded-full border border-white/30 hover:bg-white hover:text-ink-900 transition-colors">
                                <Arrow className="w-4 h-4" />
                            </button>
                        </div>
                    )}
                </div>
            </div>
        </section>
    );
}

/* ── Ticker in giant outlined type ───────────────────────────────────────── */
export function EdRibbon({ strip }) {
    if (!strip?.show || !strip.items?.length) return null;
    const items = strip.items;
    const half = Array.from({ length: Math.max(2, Math.ceil(6 / items.length)) }, () => items).flat();
    const row = (hidden) => (
        <div className="flex shrink-0 items-center" aria-hidden={hidden || undefined}>
            {half.map((f, k) => (
                <span key={k} className="flex items-center gap-8 px-8 whitespace-nowrap">
                    <span className="font-display text-5xl sm:text-6xl lg:text-[5.5rem] leading-none tracking-[-0.03em] text-transparent [-webkit-text-stroke:1px_var(--color-ink-900)]">{f.title}</span>
                    <span className="text-3xl text-gold-500" aria-hidden="true">✦</span>
                </span>
            ))}
        </div>
    );
    return (
        <section className="py-10 lg:py-14 border-b border-ink-900/[0.08] overflow-hidden" aria-label="Why shop with us">
            <ul className="sr-only">{items.map((f, k) => <li key={k}>{f.title}</li>)}</ul>
            <div className="marquee" aria-hidden="true">
                <div className="marquee-track" style={{ '--marquee-dur': `${Math.max(40, half.length * 9)}s` }}>{row(false)}{row(true)}</div>
            </div>
        </section>
    );
}

/* ── The index: categories as a staggered contact sheet ─────────────────── */
export function EdCategories({ section }) {
    if (!section?.show || !section.items?.length) return null;
    const ratios = ['aspect-[3/4]', 'aspect-[4/5]', 'aspect-[3/4]', 'aspect-[4/5]'];
    return (
        <section className="py-16 lg:py-28">
            <div className={shell}>
                <Heading eyebrow={section.eyebrow || 'The index'} title={section.title} action={section.link && section.linkLabel ? <ViewAll href={section.link}>{section.linkLabel}</ViewAll> : null} />
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-x-4 sm:gap-x-6 gap-y-12">
                    {section.items.map((cat, k) => (
                        <SmartLink key={cat.url} href={cat.url} className={`group block ${k % 2 ? 'lg:mt-20' : ''}`}>
                            <div className={`relative overflow-hidden bg-mist-100 ${ratios[k % ratios.length]}`}>
                                {cat.image && (
                                    <img src={cat.image} {...(cat.srcset ? { srcSet: cat.srcset, sizes: '(min-width: 1024px) 25vw, 50vw' } : {})} alt="" loading="lazy" decoding="async"
                                        className="absolute inset-0 w-full h-full object-cover grayscale-[15%] transition duration-[1200ms] ease-[var(--ease-lux)] group-hover:scale-[1.05] group-hover:grayscale-0" />
                                )}
                            </div>
                            <div className="mt-4 flex items-baseline justify-between gap-3 border-t border-ink-900/15 pt-3">
                                <span className="text-[11px] tracking-[0.24em] text-ink-700/60 tabular-nums">No. {pad(k + 1)}</span>
                                <span className="lux-arrow flex items-center gap-2 font-display text-2xl lg:text-3xl tracking-[-0.02em] text-ink-900">{cat.name} <Arrow className="w-4 h-4" /></span>
                            </div>
                        </SmartLink>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ── Occasions as numbered chapters ──────────────────────────────────────── */
export function EdOccasions({ section }) {
    if (!section?.show || !section.items?.length) return null;
    return (
        <section className="py-16 lg:py-24 bg-ink-900 text-white grain">
            <div className={shell}>
                <Heading dark eyebrow={section.eyebrow || 'Chapters'} title={section.title} subtitle={section.subtitle} />
                <Carousel>
                    {section.items.map((o, k) => (
                        <SmartLink key={k} href={o.link} className="group snap-start shrink-0 w-[74%] sm:w-[42%] lg:w-[28%]">
                            <div className="relative aspect-[3/4] overflow-hidden bg-white/5">
                                {o.image && <img src={o.image} alt="" loading="lazy" className="absolute inset-0 w-full h-full object-cover opacity-90 transition duration-[1200ms] group-hover:scale-105 group-hover:opacity-100" />}
                                <div className="absolute inset-0 bg-gradient-to-t from-black/70 to-transparent" />
                                <span className="absolute top-4 left-5 font-display italic text-5xl text-white/90">{ROMAN[k] || k + 1}</span>
                                <div className="absolute inset-x-0 bottom-0 p-5">
                                    <h3 className="font-display text-3xl tracking-[-0.02em]">{o.label}</h3>
                                    {o.tagline && <p className="mt-1 text-[13px] text-white/70">{o.tagline}</p>}
                                </div>
                            </div>
                        </SmartLink>
                    ))}
                </Carousel>
            </div>
        </section>
    );
}

/* ── Ranked: best sellers with their place in the list ──────────────────── */
export function EdRanked({ section }) {
    if (!section?.show || !section.cards?.length) return null;
    return (
        <section className="py-16 lg:py-28">
            <div className={shell}>
                <Heading eyebrow={section.eyebrow} title={section.title} action={section.link && section.linkLabel ? <ViewAll href={section.link}>{section.linkLabel}</ViewAll> : null} />
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-x-4 sm:gap-x-6 gap-y-12">
                    {section.cards.map((p, k) => (
                        <div key={p.id}>
                            <div className="flex items-end gap-3 mb-3">
                                <span className="font-display text-5xl lg:text-6xl leading-none tracking-[-0.04em] text-transparent [-webkit-text-stroke:1px_var(--color-ink-900)]">{pad(k + 1)}</span>
                                <span className="mb-1.5 h-px flex-1 bg-ink-900/15" aria-hidden="true" />
                            </div>
                            <ProductCard product={p} />
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ── The editor's spread ─────────────────────────────────────────────────── */
export function EdFeatured({ featured }) {
    if (!featured || featured.show === false) return null;
    const lead = featured.lead;
    const piece = lead?.type === 'product' ? lead.card : null;
    const cards = featured.cards || [];
    if (!lead && !cards.length) return null;
    const side = cards.slice(0, 2);
    const rest = cards.slice(2, 6);
    const image = piece ? piece.thumb : lead?.image;
    const srcset = piece ? piece.srcset : lead?.srcset;
    const href = piece ? piece.url : lead?.url;

    return (
        <section className="py-16 lg:py-28 bg-mist-50">
            <div className={shell}>
                <Heading eyebrow={featured.eyebrow} title={featured.title} subtitle={featured.subtitle} action={featured.link && featured.linkLabel ? <ViewAll href={featured.link}>{featured.linkLabel}</ViewAll> : null} />
                {lead ? (
                    <div className="grid lg:grid-cols-12 gap-8 lg:gap-12 items-center">
                        <SmartLink href={href} className="group lg:col-span-7 block relative overflow-hidden aspect-[4/5] lg:aspect-[5/6] bg-mist-100">
                            {image && <img src={image} alt="" loading="lazy" {...(srcset ? { srcSet: srcset, sizes: '(min-width: 1024px) 55vw, 100vw' } : {})}
                                className="absolute inset-0 w-full h-full object-cover transition duration-[1400ms] ease-[var(--ease-lux)] group-hover:scale-[1.04]" />}
                            {lead.eyebrow && <span className="absolute top-5 left-5 rounded-full bg-white/90 px-3 py-1 text-[10px] tracking-[0.24em] uppercase text-ink-900">{lead.eyebrow}</span>}
                        </SmartLink>
                        <div className="lg:col-span-5">
                            <p className="eyebrow">Editor's pick</p>
                            <h3 className="display mt-4 text-4xl lg:text-6xl">{piece ? piece.name : lead.heading}</h3>
                            {!piece && lead.text && <p className="mt-5 text-ink-700/75 leading-relaxed">{lead.text}</p>}
                            {piece && (
                                <p className="mt-5 text-2xl font-semibold text-ink-900">
                                    {piece.price_text}
                                    {piece.on_sale && <span className="ml-3 text-base font-normal text-ink-500 line-through">{piece.compare_text}</span>}
                                </p>
                            )}
                            <SmartLink href={href} className="lux-btn mt-7">{lead.button || 'Discover'} <Arrow /></SmartLink>
                            {side.length > 0 && (
                                <div className="mt-12 grid grid-cols-2 gap-4 sm:gap-6 border-t border-ink-900/10 pt-8">
                                    {side.map((p) => <ProductCard key={p.id} product={p} />)}
                                </div>
                            )}
                        </div>
                    </div>
                ) : null}
                {(lead ? rest : cards).length > 0 && (
                    <div className={`grid grid-cols-2 lg:grid-cols-4 gap-x-4 sm:gap-x-6 gap-y-12 ${lead ? 'mt-16 lg:mt-24' : ''}`}>
                        {(lead ? rest : cards).map((p) => <ProductCard key={p.id} product={p} />)}
                    </div>
                )}
            </div>
        </section>
    );
}

/* ── Gift finder as a sentence you fill in ───────────────────────────────── */
export function EdGiftFinder({ finder }) {
    const [occasion, setOccasion] = useState(finder?.profile?.occasion || '');
    if (!finder?.show || !finder.budgets?.length) return null;
    const withProfile = (url) => url + (url.includes('?') ? '&' : '?') + ['gift=1', occasion ? `occasion=${occasion}` : null].filter(Boolean).join('&');
    const word = (on) => `font-display italic transition-colors duration-300 border-b-2 ${on ? 'text-gold-600 border-gold-500' : 'text-ink-900/35 border-ink-900/15 hover:text-ink-900 hover:border-ink-900'}`;

    return (
        <section id="gift-finder" className="py-20 lg:py-32 scroll-mt-28">
            <div className={`${shell} max-w-5xl`}>
                <p className="eyebrow flex items-center gap-3"><span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />{finder.eyebrow || 'Gift finder'}</p>
                <div className="mt-8 display text-[2.1rem] sm:text-5xl lg:text-[4rem] !leading-[1.25]">
                    <span>I'm shopping for </span>
                    {finder.occasions?.length > 0 ? (
                        <span role="group" aria-label="What is the occasion?">
                            {finder.occasions.map(([key, label], k) => (
                                <span key={key}>
                                    <button type="button" onClick={() => setOccasion(occasion === key ? '' : key)} aria-pressed={occasion === key} className={word(occasion === key)}>{label.toLowerCase()}</button>
                                    {k < finder.occasions.length - 1 ? <span className="text-ink-900/30">, </span> : null}
                                </span>
                            ))}
                        </span>
                    ) : <span className="italic">someone special</span>}
                    <span>, and I'd like to spend </span>
                    {finder.budgets.map((b, k) => (
                        <span key={k}>
                            <SmartLink href={withProfile(b.url)} className={word(false) + ' !text-ink-900 hover:!text-gold-600'}>{b.label.toLowerCase()}</SmartLink>
                            {k < finder.budgets.length - 1 ? <span className="text-ink-900/30"> or </span> : null}
                        </span>
                    ))}
                    <span>.</span>
                </div>
                {finder.text && <p className="mt-8 max-w-xl text-ink-700/70 leading-relaxed">{finder.text}</p>}
                {(finder.promises || []).length > 0 && (
                    <ul className="mt-10 grid sm:grid-cols-3 gap-6 border-t border-ink-900/10 pt-8">
                        {finder.promises.map(({ icon, title, text }) => (
                            <li key={title} className="flex gap-3">
                                <IconOrGlyph value={icon} fallback="gift" className="w-5 h-5 text-gold-600 shrink-0 mt-0.5" />
                                <span><span className="block text-sm font-medium text-ink-900">{title}</span><span className="block text-[12px] text-ink-700/60 mt-0.5">{text}</span></span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

/* ── One pull-quote at a time ────────────────────────────────────────────── */
export function EdReviews({ reviews, section = {} }) {
    const [i, setI] = useState(0);
    const [paused, setPaused] = useState(false);
    const n = reviews?.length || 0;
    useEffect(() => {
        if (n < 2 || paused) return undefined;
        const t = setInterval(() => setI((v) => (v + 1) % n), 7000);
        return () => clearInterval(t);
    }, [n, paused]);
    if (!n) return null;
    const r = reviews[i];

    return (
        <section className="py-20 lg:py-32 border-y border-ink-900/[0.08]" onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)}>
            <div className={`${shell} max-w-5xl text-center`}>
                <p className="eyebrow">{section.eyebrow || 'Customer love'}</p>
                <h2 className="sr-only">{section.title || "What they're saying"}</h2>
                <span className="block mt-6 font-display text-8xl leading-none text-gold-500" aria-hidden="true">“</span>
                <blockquote key={i} className="rise-in display text-[1.7rem] sm:text-4xl lg:text-5xl !leading-[1.2]" aria-live="polite">{r.quote}</blockquote>
                <div className="mt-8 flex flex-col items-center gap-2">
                    <span className="flex text-gold-500">{[1, 2, 3, 4, 5].map((s) => <Star key={s} className="w-4 h-4" off={s > r.rating} />)}</span>
                    <span className="text-sm font-medium text-ink-900">{r.author}</span>
                    {r.product && <SmartLink href={r.product.url} className="text-[12px] text-ink-700/60 lux-link">{r.product.name}</SmartLink>}
                </div>
                {n > 1 && (
                    <div className="mt-10 flex items-center justify-center gap-4">
                        <button type="button" onClick={() => setI((i - 1 + n) % n)} aria-label="Previous review" className="grid place-items-center w-10 h-10 rounded-full border border-ink-900/15 hover:bg-ink-900 hover:text-white transition-colors"><Arrow className="w-4 h-4 rotate-180" /></button>
                        <span className="text-[12px] tabular-nums tracking-[0.2em] text-ink-700/60">{pad(i + 1)} / {pad(n)}</span>
                        <button type="button" onClick={() => setI((i + 1) % n)} aria-label="Next review" className="grid place-items-center w-10 h-10 rounded-full border border-ink-900/15 hover:bg-ink-900 hover:text-white transition-colors"><Arrow className="w-4 h-4" /></button>
                    </div>
                )}
            </div>
        </section>
    );
}

/* ── The promise as a full-bleed manifesto ───────────────────────────────── */
export function EdPromise({ promise }) {
    if (!promise?.show) return null;
    return (
        <section className="relative isolate overflow-hidden bg-ink-900 text-white">
            {promise.image && (
                <img src={promise.image} {...(promise.imageSrcset ? { srcSet: promise.imageSrcset, sizes: '100vw' } : {})} alt="" loading="lazy"
                    className="absolute inset-0 -z-10 w-full h-full object-cover opacity-45" />
            )}
            <div className="absolute inset-0 -z-10 bg-gradient-to-b from-black/50 via-black/30 to-black/70" />
            <div className={`${shell} py-24 lg:py-40 text-center max-w-4xl`}>
                <p className="eyebrow !text-white/70">{promise.eyebrow}</p>
                <h2 className="display !text-white mt-6 text-5xl sm:text-6xl lg:text-8xl">{promise.title}</h2>
                {promise.text && <p className="mt-8 text-white/80 text-lg leading-relaxed max-w-2xl mx-auto">{promise.text}</p>}
                <ol className="mt-14 grid sm:grid-cols-3 gap-6 text-left border-t border-white/20 pt-8">
                    {promise.badges.map((b, k) => (
                        <li key={k} className="flex gap-3">
                            <span className="text-[11px] tabular-nums tracking-[0.2em] text-white/50 pt-1">{pad(k + 1)}</span>
                            <span><span className="block font-display text-xl">{b.title}</span><span className="block text-[13px] text-white/60 mt-1">{b.text}</span></span>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}

/* ── Just landed: a wide rail ────────────────────────────────────────────── */
export function EdRail({ section }) {
    if (!section?.show || !section.cards?.length) return null;
    return (
        <section className="py-16 lg:py-28">
            <div className={shell}>
                <Heading eyebrow={section.eyebrow} title={section.title} action={section.link && section.linkLabel ? <ViewAll href={section.link}>{section.linkLabel}</ViewAll> : null} />
                <Carousel>
                    {section.cards.map((p) => (
                        <div key={p.id} className="snap-start shrink-0 w-[62%] sm:w-[40%] lg:w-[30%]"><ProductCard product={p} /></div>
                    ))}
                </Carousel>
            </div>
        </section>
    );
}
