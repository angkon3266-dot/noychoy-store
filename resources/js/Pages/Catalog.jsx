import { useEffect, useMemo, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import Layout from '../Shared/Chrome/Layout';
import ProductCard from '../Shared/ProductCard';
import Pagination from '../Shared/Pagination';
import Icon from '../Shared/Icons';
import { Link } from '@inertiajs/react';

// Shop / category / best-sellers grid. Filters and sort submit as GET query
// params — same URLs as before, so crawlers, ads and old links keep working.
//
// Filter/sort clicks ask for only the props that actually change, so the
// server skips rebuilding the shared `chrome` payload (menus, footer,
// notifications) on every checkbox.
const PARTIAL = {
    preserveState: true,
    preserveScroll: true,
    only: ['products', 'filters', 'sort', 'title', 'pageTitle', 'seoTitle'],
};
export default function Catalog({ title, description = null, products, filters, sort, searchQuery, noResults = null }) {
    const { props, url } = usePage();
    const urls = props.chrome?.urls || {};
    const [showFilters, setShowFilters] = useState(false);

    // Search event for Meta Pixel (matches the Blade @push('meta-events')).
    useEffect(() => {
        if (searchQuery && window.track) window.track('Search', { search_string: searchQuery });
    }, [searchQuery]);

    const query = useMemo(() => {
        const params = new URLSearchParams(window.location.search);
        return params;
    }, [url]);   // re-read after every navigation, including filter toggles

    // `colors` belongs here too: without it, picking a colour and nothing else
    // left no way back — "Clear all" never appeared.
    const hasActiveFilters = ['attr', 'cf', 'tags', 'colors', 'price_range', 'price_min', 'price_max', 'in_stock', 'on_sale', 'category']
        .some((k) => [...query.keys()].some((key) => key === k || key.startsWith(k + '[')));

    /** Rebuild the querystring from the checked boxes and navigate. */
    const applyFilters = (param, value, checked) => {
        const params = new URLSearchParams(window.location.search);
        const existing = params.getAll(param);
        params.delete(param);
        const next = checked ? [...existing, value] : existing.filter((v) => v !== value);
        next.forEach((v) => params.append(param, v));
        params.delete('page');
        router.get(`${window.location.pathname}?${params.toString()}`, {}, PARTIAL);
    };

    const applySort = (value) => {
        const params = new URLSearchParams(window.location.search);
        params.set('sort', value);
        params.delete('page');
        router.get(`${window.location.pathname}?${params.toString()}`, {}, PARTIAL);
    };

    const clearUrl = searchQuery
        ? `${window.location.pathname}?q=${encodeURIComponent(searchQuery)}`
        : window.location.pathname;

    // How many filters are on, for the phone button's badge.
    const activeCount = ['attr', 'cf', 'tags', 'colors', 'price_range', 'price_min', 'price_max', 'in_stock', 'on_sale', 'category']
        .reduce((n, k) => n + [...query.keys()].filter((key) => key === k || key.startsWith(k + '[')).length, 0);

    // The phone filter sheet locks the page behind it while open.
    useEffect(() => {
        if (!showFilters) return undefined;
        const mq = window.matchMedia('(max-width: 1023px)');
        if (mq.matches) document.body.style.overflow = 'hidden';
        const esc = (e) => e.key === 'Escape' && setShowFilters(false);
        window.addEventListener('keydown', esc);
        return () => { document.body.style.overflow = ''; window.removeEventListener('keydown', esc); };
    }, [showFilters]);

    const sortSelect = (
        <label className="relative inline-flex items-center">
            <span className="sr-only">Sort products</span>
            <select value={sort} onChange={(e) => applySort(e.target.value)} aria-label="Sort products" className="appearance-none rounded-full border border-ink-900/15 bg-transparent pl-4 pr-10 h-11 text-[13px] text-ink-900 hover:border-ink-900 focus:border-ink-900 focus:outline-none focus:ring-4 focus:ring-ink-900/5 transition-colors cursor-pointer">
                <option value="new">Newest</option>
                <option value="popular">Most popular</option>
                <option value="best_selling">Best selling</option>
                <option value="price_asc">Price: low to high</option>
                <option value="price_desc">Price: high to low</option>
                <option value="name">Name A–Z</option>
            </select>
            <Icon name="chevronDown" className="pointer-events-none absolute right-4 w-3.5 h-3.5 text-ink-900" strokeWidth={2} />
        </label>
    );

    return (
        <div className="mx-auto max-w-[1440px] px-4 sm:px-6 lg:px-10 pt-8 lg:pt-14 pb-10">
            <header className="pb-8 lg:pb-12 border-b border-ink-900/[0.08]">
                <p className="eyebrow flex items-center gap-3">
                    <span className="h-px w-8 bg-ink-900/25" aria-hidden="true" />
                    {searchQuery ? 'Search' : 'The collection'}
                </p>
                <div className="mt-4 flex flex-wrap items-end justify-between gap-x-10 gap-y-4">
                    <div className="max-w-3xl">
                        <h1 className="display text-[2.6rem] sm:text-6xl lg:text-7xl">{title}</h1>
                        {description && <p className="text-ink-700/75 mt-4 text-[15px] leading-relaxed max-w-2xl">{description}</p>}
                    </div>
                    <p className="text-[13px] text-ink-700/70 tabular-nums">{products.total} {products.total === 1 ? 'piece' : 'pieces'}</p>
                </div>
            </header>

            {/* Toolbar */}
            <div className="flex items-center justify-between gap-3 py-5">
                <button
                    type="button"
                    onClick={() => setShowFilters(!showFilters)}
                    aria-expanded={showFilters}
                    className="lg:hidden inline-flex items-center gap-2 rounded-full bg-ink-900 text-white pl-4 pr-5 h-11 text-[13px] font-medium"
                >
                    <Icon name="funnel" className="w-4 h-4 shrink-0" strokeWidth={1.6} /> Filters
                    {activeCount > 0 && <span className="grid place-items-center min-w-5 h-5 px-1 rounded-full bg-white text-ink-900 text-[11px] font-semibold">{activeCount}</span>}
                </button>
                <p className="hidden lg:block text-[13px] text-ink-700/70">
                    {hasActiveFilters ? <>Filtered · <Link href={clearUrl} className="lux-link lux-link-on text-ink-900">Clear all</Link></> : 'Refine by what matters to you'}
                </p>
                {sortSelect}
            </div>

            <div className="lg:grid lg:grid-cols-[250px_1fr] lg:gap-12 xl:gap-16">
                {/* Filters: a sidebar on desktop, a bottom sheet on phones. */}
                {showFilters && (
                    <button type="button" tabIndex={-1} aria-hidden="true" onClick={() => setShowFilters(false)} className="lg:hidden fixed inset-0 z-[55] bg-ink-900/30 backdrop-blur-[2px]" />
                )}
                <aside
                    aria-label="Product filters"
                    className={`${showFilters ? 'fixed' : 'hidden'} lg:block lg:static inset-x-0 bottom-0 z-[56] max-h-[85vh] lg:max-h-none overflow-y-auto rounded-t-[28px] lg:rounded-none bg-white lg:bg-transparent px-6 pt-3 pb-6 lg:p-0 shadow-[0_-30px_60px_-30px_rgb(0_0_0/0.4)] lg:shadow-none`}
                >
                    <div className="lg:sticky lg:top-28 lg:max-h-[calc(100vh-8rem)] lg:overflow-y-auto no-scrollbar lg:pb-6">
                        <span className="lg:hidden mx-auto mb-4 block h-1 w-10 rounded-full bg-ink-900/15" aria-hidden="true" />
                        <div className="flex items-center justify-between mb-2">
                            <h2 className="font-display text-2xl lg:text-xl tracking-[-0.01em] text-ink-900">Filter</h2>
                            {hasActiveFilters && (
                                <Link href={clearUrl} className="text-[12px] text-ink-700/70 hover:text-ink-900 lux-link lux-link-on">Clear all</Link>
                            )}
                        </div>

                        {filters.length ? filters.map((group) => (
                            <details key={group.label} open className="group/f border-b border-ink-900/[0.08] py-4">
                                <summary className="flex items-center justify-between cursor-pointer list-none text-[12px] font-medium uppercase tracking-[0.16em] text-ink-900">
                                    {group.label}
                                    <Icon name="chevronDown" className="w-3.5 h-3.5 text-ink-700/70 transition-transform duration-300 group-open/f:rotate-180" strokeWidth={2} />
                                </summary>
                                <div className="mt-3 space-y-0.5 max-h-64 overflow-y-auto pr-1">
                                    {group.options.map((opt) => (
                                        <label key={opt.value} className="flex items-center gap-3 text-[14px] text-ink-800 py-1.5 cursor-pointer hover:text-ink-900">
                                            <input
                                                type="checkbox"
                                                defaultChecked={opt.checked}
                                                onChange={(e) => applyFilters(group.param, opt.value, e.target.checked)}
                                                className="peer sr-only"
                                            />
                                            <span className="grid place-items-center w-[18px] h-[18px] shrink-0 rounded-[6px] border border-ink-900/25 text-transparent transition-colors peer-checked:bg-ink-900 peer-checked:border-ink-900 peer-checked:text-white peer-focus-visible:ring-4 peer-focus-visible:ring-ink-900/10" aria-hidden="true">
                                                <Icon name="check" className="w-3 h-3" strokeWidth={2.5} />
                                            </span>
                                            {group.is_color && (
                                                opt.hex === 'multi' ? (
                                                    <span className="w-4 h-4 rounded-full ring-1 ring-ink-900/10" style={{ background: 'conic-gradient(red,orange,yellow,green,blue,violet,red)' }} />
                                                ) : opt.hex ? (
                                                    <span className="w-4 h-4 rounded-full ring-1 ring-ink-900/10" style={{ background: opt.hex }} />
                                                ) : null
                                            )}
                                            <span>{opt.label}</span>
                                        </label>
                                    ))}
                                </div>
                            </details>
                        )) : (
                            <p className="text-sm text-ink-700/70 py-4">No filters configured.</p>
                        )}

                        <button type="button" onClick={() => setShowFilters(false)} className="lg:hidden btn-primary w-full mt-6">
                            Show {products.total} {products.total === 1 ? 'piece' : 'pieces'}
                        </button>
                    </div>
                </aside>

                {/* Products */}
                <div className="min-w-0">
                    {products.data.length === 0 ? (
                        /* A search that found nothing used to end the visit:
                           one generic line and a button back to the top. Now it
                           climbs down three rungs — the same words spelled the
                           way we spell them, then any one of the words, then
                           simply what sells. */
                        <div className="rounded-[28px] bg-mist-50 p-8 sm:p-12">
                            {noResults ? (
                                <>
                                    <div className="text-center">
                                        <p className="font-display text-2xl sm:text-3xl text-ink-900">
                                            Nothing matched &ldquo;{noResults.term}&rdquo;
                                        </p>
                                        {noResults.didYouMean && (
                                            <p className="mt-3 text-ink-700/75">
                                                Did you mean{' '}
                                                <Link href={noResults.didYouMeanUrl} className="text-ink-900 font-medium lux-link lux-link-on">
                                                    {noResults.didYouMean}
                                                </Link>?
                                            </p>
                                        )}
                                    </div>

                                    {noResults.nearest.length > 0 && (
                                        <div className="mt-12">
                                            <h2 className="font-display text-2xl mb-6">Closest we have</h2>
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-10">
                                                {noResults.nearest.map((p) => <ProductCard key={p.id} product={p} />)}
                                            </div>
                                        </div>
                                    )}

                                    {noResults.popular.length > 0 && (
                                        <div className="mt-12">
                                            <h2 className="font-display text-2xl mb-6">People are buying</h2>
                                            <div className="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-10">
                                                {noResults.popular.map((p) => <ProductCard key={p.id} product={p} />)}
                                            </div>
                                        </div>
                                    )}

                                    <div className="mt-12 text-center">
                                        <Link href={urls.shop || '/shop'} className="btn-outline">Browse all jewelry</Link>
                                    </div>
                                </>
                            ) : (
                                <div className="text-center py-6">
                                    <p className="font-display text-2xl text-ink-900">No pieces match these filters</p>
                                    <p className="mt-2 text-sm text-ink-700/70">Try removing one, or start again from everything.</p>
                                    <div className="mt-6 flex flex-wrap justify-center gap-2">
                                        {hasActiveFilters && <Link href={clearUrl} className="btn-primary">Clear filters</Link>}
                                        <Link href={urls.shop || '/shop'} className="btn-outline">Browse all jewelry</Link>
                                    </div>
                                </div>
                            )}
                        </div>
                    ) : (
                        <>
                            <div className="grid grid-cols-2 md:grid-cols-3 gap-x-3 sm:gap-x-5 gap-y-10 lg:gap-y-14">
                                {products.data.map((p) => <ProductCard key={p.id} product={p} />)}
                            </div>
                            <div className="mt-14">
                                <Pagination links={products.links} />
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}

Catalog.layout = (page) => <Layout>{page}</Layout>;
