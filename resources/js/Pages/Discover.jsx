import Layout from '../Shared/Chrome/Layout';
import SmartLink from '../Shared/SmartLink';

export default function Discover({ tiles, categories }) {
    const items = tiles.length
        ? tiles.map((t) => ({ href: t.link, image: t.image, name: t.name }))
        : categories.map((c) => ({ href: c.url, image: c.image, name: c.name }));

    return (
        <div className="mx-auto max-w-6xl px-4 py-6 md:py-10">
            <h1 className="font-display text-2xl md:text-3xl font-semibold mb-6">Discover</h1>

            {items.length ? (
                <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 md:gap-4">
                    {items.map((item, i) => (
                        <SmartLink key={i} href={item.href} className="group block rounded-2xl border border-ink-100 overflow-hidden bg-white hover:shadow-md transition">
                            <div className="aspect-square bg-gold-50 flex items-center justify-center overflow-hidden">
                                {item.image ? (
                                    <img src={item.image} alt={item.name || ''} loading="lazy" decoding="async" className="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                                ) : (
                                    <span className="font-display text-lg text-gold-400">{(item.name || '').slice(0, 14)}</span>
                                )}
                            </div>
                            {item.name && <div className="px-3 py-3 text-center font-medium text-sm text-ink-800 group-hover:text-gold-700">{item.name}</div>}
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
