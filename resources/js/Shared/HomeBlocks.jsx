import { useEffect, useState } from 'react';
import ProductCard from './ProductCard';
import Carousel from './Carousel';
import SmartLink from './SmartLink';
import useCountdown from './useCountdown';
import { useCart } from './CartContext';
import { IconOrGlyph } from './Icons';

// Renders the admin section-builder blocks — the React port of
// <x-home-block>. Data shapes come from HomePageData::block().
export default function HomeBlocks({ blocks }) {
    if (!blocks?.length) return null;
    return blocks.map((block, i) => <Block key={i} block={block} />);
}

function SectionHeading({ title, link = null, linkLabel = 'View All' }) {
    return (
        <section className="mx-auto max-w-7xl px-4 pt-10 pb-5">
            <div className="relative text-center border-b border-ink-100 pb-3">
                <h2 className="inline-block font-display text-2xl sm:text-3xl tracking-wide text-ink-900">
                    {title}
                    <span className="block h-0.5 w-16 bg-gold-500 mt-2 mx-auto"></span>
                </h2>
                {link && <SmartLink href={link} className="absolute right-0 bottom-3 text-sm text-ink-700/70 hover:text-gold-700 whitespace-nowrap">{linkLabel}</SmartLink>}
            </div>
        </section>
    );
}

function Block({ block }) {
    switch (block.type) {
        case 'banner': {
            const cols = block.layout === 'single' ? 1 : (block.layout === 'dual' ? 2 : 3);
            return (
                <section className="mx-auto max-w-7xl px-4 py-6">
                    <div className="grid gap-4" style={{ gridTemplateColumns: `repeat(${cols}, minmax(0,1fr))` }}>
                        {block.images.map((im, i) => (
                            <SmartLink key={i} href={im.link || '#'} aria-label={block.title || 'Shop the collection'} className="block overflow-hidden rounded-2xl group">
                                <img src={im.image} alt="" className="w-full h-full object-cover transition duration-700 group-hover:scale-105" loading="lazy" />
                            </SmartLink>
                        ))}
                    </div>
                </section>
            );
        }

        case 'product_carousel':
            return (
                <>
                    <SectionHeading title={block.title || 'Products'} link={block.view_all_link} />
                    <Carousel className="mx-auto max-w-7xl px-4 pb-10">
                        {block.cards.map((p) => (
                            <div key={p.id} className="snap-start shrink-0 w-52 sm:w-60"><ProductCard product={p} /></div>
                        ))}
                    </Carousel>
                </>
            );

        case 'banner_carousel':
            return (
                <section className="mx-auto max-w-7xl px-4 py-8">
                    {/* The heading exists so the blocks below it are not
                        orphaned h3s. When the owner leaves the title blank it
                        stays in the accessibility tree but off the page —
                        putting words there that nobody wrote is a content
                        decision, not an accessibility fix. */}
                    <h2 className={block.title ? 'font-display text-2xl sm:text-3xl text-ink-900 mb-4' : 'sr-only'}>{block.title || 'Shop the collection'}</h2>
                    <div className="grid md:grid-cols-[300px_1fr] gap-5 items-stretch">
                        {block.banner && (
                            <SmartLink href={block.banner.link || '#'} aria-label={block.title || 'Shop the collection'} className="relative block overflow-hidden rounded-2xl group min-h-[220px]">
                                <img src={block.banner.image} alt="" className="absolute inset-0 w-full h-full object-cover transition duration-700 group-hover:scale-105" loading="lazy" />
                            </SmartLink>
                        )}
                        <div className="min-w-0">
                            {block.cards.length > 0 && (
                                <Carousel>
                                    {block.cards.map((p) => (
                                        <div key={p.id} className="snap-start shrink-0 w-48 sm:w-56"><ProductCard product={p} /></div>
                                    ))}
                                </Carousel>
                            )}
                        </div>
                    </div>
                </section>
            );

        case 'video':
            return (
                <>
                    {block.title
                        ? <SectionHeading title={block.title} />
                        : <h2 className="sr-only">Video</h2>}
                    <section className="mx-auto max-w-7xl px-4 pb-12 grid gap-6 md:grid-cols-2">
                        {block.videos.map((v, i) => (
                            <div key={i}>
                                {v.title && <h3 className="text-sm font-semibold uppercase tracking-wide text-ink-700/70 mb-3">{v.title}</h3>}
                                <div className="aspect-video overflow-hidden rounded-xl bg-ink-900">
                                    {v.meta.type === 'file' ? (
                                        <video src={v.meta.src} controls preload="metadata" className="w-full h-full object-cover" />
                                    ) : (
                                        <iframe src={v.meta.embed} title={v.title} className="w-full h-full" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowFullScreen />
                                    )}
                                </div>
                            </div>
                        ))}
                    </section>
                </>
            );

        case 'cta_banner': {
            const cta = block.cta;
            const alignCls = { left: 'text-left', right: 'text-right ml-auto', center: 'text-center mx-auto' }[cta.align];
            const height = { sm: 'min-h-[280px]', md: 'min-h-[420px]', lg: 'min-h-[560px]' }[cta.height] || 'min-h-[420px]';
            return (
                <section className={`relative w-full ${height} flex overflow-hidden my-6`}>
                    {cta.image && <img src={cta.image} alt="" className="absolute inset-0 w-full h-full object-cover" loading="lazy" />}
                    <div className="absolute inset-0 bg-black/40"></div>
                    <div className="relative z-10 mx-auto max-w-7xl w-full px-6 py-12 flex flex-col justify-center">
                        <div className={`max-w-xl ${alignCls}`}>
                            {cta.eyebrow && <p className="uppercase tracking-[0.3em] text-xs text-white/80 mb-3">{cta.eyebrow}</p>}
                            {cta.heading && <h2 className="font-display text-3xl sm:text-5xl font-semibold text-white leading-tight">{cta.heading}</h2>}
                            {cta.subheading && <p className="mt-4 text-white/85 text-lg">{cta.subheading}</p>}
                            {cta.button_text && (
                                <div className="mt-7">
                                    <SmartLink href={cta.button_link} className="inline-flex items-center gap-2 rounded-full bg-white text-ink-900 px-8 py-3.5 text-sm tracking-wide hover:bg-gold-100 transition">{cta.button_text}</SmartLink>
                                </div>
                            )}
                        </div>
                    </div>
                </section>
            );
        }

        case 'reviews':
            return (
                <section className="py-14 bg-gold-50/60">
                    <div className="mx-auto max-w-7xl px-4">
                        <div className="text-center mb-10">
                            <p className="uppercase tracking-[0.3em] text-xs text-gold-700 mb-3">Customer love</p>
                            <h2 className="font-display text-3xl sm:text-4xl text-ink-900">{block.title || "What they're saying"}</h2>
                        </div>
                        <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                            {block.items.map((t, i) => (
                                <div key={i} className="rounded-2xl bg-white border border-ink-100 p-6 shadow-sm flex flex-col">
                                    <div className="text-gold-500 tracking-wide mb-3" aria-label={`${t.rating} star review`}>{'★'.repeat(Math.max(1, Math.min(5, t.rating)))}</div>
                                    <p className="font-display italic text-ink-800 leading-relaxed flex-1">“{t.quote}”</p>
                                    <div className="flex items-center gap-3 mt-5">
                                        <div className="w-10 h-10 rounded-full bg-gold-100 text-gold-700 text-xs font-semibold flex items-center justify-center shrink-0">
                                            {t.author.trim().split(/\s+/).map((w) => w[0]).slice(0, 2).join('')}
                                        </div>
                                        <div className="min-w-0">
                                            <p className="text-sm font-semibold text-ink-900">{t.author}</p>
                                            {t.meta && (t.link
                                                ? <SmartLink href={t.link} className="text-xs text-ink-700/70 hover:text-gold-700 truncate block">{t.meta}</SmartLink>
                                                : <p className="text-xs text-ink-700/70 truncate">{t.meta}</p>)}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                </section>
            );

        case 'hero_cta': {
            const h = block.hero;
            const dark = h.image && h.dark;
            return (
                <section className={`relative overflow-hidden ${h.image ? '' : 'bg-gold-50'}`}>
                    {h.image && <img src={h.image} alt="" className="absolute inset-0 w-full h-full object-cover" />}
                    {h.image && <div className={`absolute inset-0 ${h.dark ? 'bg-black/50' : 'bg-white/60'}`}></div>}
                    <div className="relative mx-auto max-w-5xl px-5 py-20 sm:py-28 text-center">
                        {h.eyebrow && <p className={`uppercase tracking-[0.35em] text-xs mb-4 ${dark ? 'text-white/80' : 'text-gold-700'}`}>{h.eyebrow}</p>}
                        <h2 className={`font-display text-4xl sm:text-6xl font-semibold leading-tight ${dark ? 'text-white' : 'text-ink-900'}`}>{h.heading}</h2>
                        {h.subheading && <p className={`mt-5 text-lg max-w-2xl mx-auto ${dark ? 'text-white/85' : 'text-ink-700/75'}`}>{h.subheading}</p>}
                        <div className="mt-9 flex flex-wrap gap-3 justify-center">
                            {h.cta_text && <a href={h.cta_link} className="inline-flex items-center rounded-full bg-gold-600 text-white px-9 py-4 text-sm font-medium tracking-wide hover:bg-gold-700 transition shadow-lg hover:shadow-xl hover:-translate-y-0.5 duration-300">{h.cta_text}</a>}
                            {h.cta2_text && <a href={h.cta2_link} className={`inline-flex items-center rounded-full px-9 py-4 text-sm tracking-wide transition border ${dark ? 'border-white/70 text-white hover:bg-white/10' : 'border-gold-300 text-gold-800 hover:bg-gold-100'}`}>{h.cta2_text}</a>}
                        </div>
                        {h.note && <p className={`mt-5 text-xs ${dark ? 'text-white/70' : 'text-ink-700/70'}`}>{h.note}</p>}
                    </div>
                </section>
            );
        }

        case 'benefits':
            return (
                <section className="mx-auto max-w-6xl px-4 py-14">
                    <h2 className={block.title ? 'font-display text-3xl text-center mb-10' : 'sr-only'}>{block.title || 'Why shop with us'}</h2>
                    <div className={`grid gap-6 sm:grid-cols-2 ${{ 2: 'lg:grid-cols-2', 3: 'lg:grid-cols-3', 4: 'lg:grid-cols-4' }[Math.min(4, Math.max(2, block.items.length))]}`}>
                        {block.items.map((b, i) => (
                            <div key={i} className="text-center px-4">
                                <div className="mb-3 flex justify-center text-gold-700"><IconOrGlyph value={b.icon} fallback="sparkle" className="w-8 h-8" /></div>
                                <h3 className="font-semibold mb-1.5">{b.title}</h3>
                                {b.text && <p className="text-sm text-ink-700/70 leading-relaxed">{b.text}</p>}
                            </div>
                        ))}
                    </div>
                </section>
            );

        case 'countdown':
            return <CountdownBlock c={block.countdown} />;

        case 'buy_box':
            return <BuyBoxBlock block={block} />;

        case 'faq':
            return (
                <section className="mx-auto max-w-3xl px-4 py-14">
                    <h2 className="font-display text-3xl text-center mb-8">{block.title || 'Questions, answered'}</h2>
                    <div className="divide-y divide-ink-100 border-y border-ink-100">
                        {block.items.map((f, i) => (
                            <details key={i} className="group py-4">
                                <summary className="flex items-center justify-between cursor-pointer font-medium list-none">
                                    <span>{f.q}</span>
                                    <svg aria-hidden="true" className="w-5 h-5 shrink-0 text-ink-700/40 transition-transform duration-300 group-open:rotate-45" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24"><path strokeLinecap="round" d="M12 4v16m8-8H4" /></svg>
                                </summary>
                                {f.a && <p className="text-sm text-ink-700/75 mt-3 leading-relaxed">{f.a}</p>}
                            </details>
                        ))}
                    </div>
                </section>
            );

        case 'sticky_cta':
            return <StickyCtaBlock s={block.sticky} />;

        case 'richtext':
            // Admin-authored HTML — the same trust boundary as Blade's {!! !!}.
            return <section className="mx-auto max-w-4xl px-4 py-8 prose prose-sm sm:prose" dangerouslySetInnerHTML={{ __html: block.html }} />;

        default:
            return null;
    }
}

function CountdownBlock({ c }) {
    const { expired, units } = useCountdown(c.endsAt);
    if (expired) return null;
    return (
        <section className="bg-ink-900 text-white py-10">
            <div className="mx-auto max-w-4xl px-4 text-center">
                {c.title && <p className="font-display text-2xl sm:text-3xl mb-5">{c.title}</p>}
                <div className="flex justify-center gap-3 sm:gap-5">
                    {units.map((u) => (
                        <div key={u.label} className="min-w-[64px] rounded-xl bg-white/10 px-3 py-3">
                            <div className="text-2xl sm:text-3xl font-semibold tabular-nums">{String(u.value).padStart(2, '0')}</div>
                            <div className="text-[10px] uppercase tracking-widest text-white/60 mt-1">{u.label}</div>
                        </div>
                    ))}
                </div>
                {c.cta_text && <a href={c.cta_link} className="inline-flex mt-7 rounded-full bg-gold-500 text-ink-900 px-8 py-3.5 text-sm font-medium hover:bg-gold-400 transition">{c.cta_text}</a>}
            </div>
        </section>
    );
}

function BuyBoxBlock({ block }) {
    const { add } = useCart();
    return (
        <section id="buy" className="mx-auto max-w-6xl px-4 py-14">
            <h2 className={block.title ? 'font-display text-3xl text-center mb-10' : 'sr-only'}>{block.title || 'Shop the edit'}</h2>
            <div className={`grid gap-6 ${block.items.length === 1 ? 'max-w-md mx-auto' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
                {block.items.map((p, i) => (
                    <div key={i} className="card p-4 text-center">
                        <SmartLink href={p.url} className="block overflow-hidden rounded-xl bg-gold-100 aspect-square">
                            {p.thumb && <img src={p.thumb} alt={p.name} className="w-full h-full object-cover hover:scale-105 transition duration-700" loading="lazy" />}
                        </SmartLink>
                        <h3 className="font-medium mt-4">{p.name}</h3>
                        <p className="text-gold-700 text-lg font-semibold mt-1">
                            {p.price_text}
                            {p.compare_text && <span className="text-ink-500 line-through text-sm ml-1">{p.compare_text}</span>}
                        </p>
                        <button type="button" onClick={() => add(p.add_url, { qty: 1 })} className="btn-primary w-full mt-4">{block.cta_label}</button>
                        <p className="text-xs text-ink-700/70 mt-2">Cash on delivery · pay when it arrives</p>
                    </div>
                ))}
            </div>
        </section>
    );
}

function StickyCtaBlock({ s }) {
    const [show, setShow] = useState(false);
    useEffect(() => {
        const onScroll = () => setShow(window.scrollY > 500);
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);
    if (!show) return null;
    return (
        <>
            <div className="fixed inset-x-0 bottom-0 z-[60] border-t border-ink-100 bg-white/95 backdrop-blur shadow-[0_-4px_20px_rgba(0,0,0,0.08)]">
                <div className="mx-auto max-w-5xl px-4 py-3 flex items-center gap-4">
                    <p className="text-sm font-medium flex-1 min-w-0 truncate">{s.text}</p>
                    <a href={s.link} className="btn-primary whitespace-nowrap shrink-0">{s.button}</a>
                </div>
            </div>
            <div className="h-16"></div>
        </>
    );
}
