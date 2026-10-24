import Layout from '../Shared/Chrome/Layout';
import SmartLink from '../Shared/SmartLink';

export default function Discover({ tiles, categories }) {
    const items = tiles.length
        ? tiles.map((t) => ({ href: t.link, image: t.image, name: t.name }))
        : categories.map((c) => ({ href: c.url, image: c.image, name: c.name }));

    return (
        <div className="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-10 pt-8 lg:pt-14 pb-6">
            <p className="eyebrow flex items-center gap-3"><span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />Explore</p>
            <h1 className="display mt-4 mb-10 lg:mb-14 text-[2.6rem] sm:text-6xl lg:text-7xl">Discover</h1>

            {items.length ? (
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-5">
                    {items.map((item, i) => (
                        <SmartLink key={i} href={item.href} className="group relative block overflow-hidden rounded-[22px] bg-mist-100 aspect-[3/4]">
                            {item.image ? (
                                <img src={item.image} alt="" loading="lazy" decoding="async" className="absolute inset-0 h-full w-full object-cover transition duration-[1200ms] ease-[var(--ease-lux)] group-hover:scale-[1.06]" />
                            ) : (
                                <span className="absolute inset-0 grid place-items-center p-4 text-center display text-2xl !text-ink-900/40">{(item.name || '').slice(0, 14)}</span>
                            )}
                            <div className="absolute inset-0 bg-gradient-to-t from-black/60 via-black/0 to-transparent" />
                            <span className="absolute top-4 left-4 text-[11px] tracking-[0.2em] text-white/80 tabular-nums">{String(i + 1).padStart(2, '0')}</span>
                            {item.name && (
                                <div className="absolute inset-x-0 bottom-0 p-4 lg:p-5 flex items-end justify-between gap-2">
                                    <span className="display !text-white text-xl sm:text-2xl">{item.name}</span>
                                    <span className="hidden sm:grid place-items-center w-9 h-9 shrink-0 rounded-full glass-dark text-white transition duration-500 group-hover:bg-white group-hover:text-ink-900 group-hover:-rotate-45">
                                        <svg aria-hidden="true" className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="1.6" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M4 12h16m0 0l-6-6m6 6l-6 6" /></svg>
                                    </span>
                                </div>
                            )}
                        </SmartLink>
                    ))}
                </div>
            ) : (
                <p className="text-center text-sm text-ink-700/70 py-10">Discover tiles haven't been set up yet.</p>
            )}
        </div>
    );
}

Discover.layout = (page) => <Layout>{page}</Layout>;
