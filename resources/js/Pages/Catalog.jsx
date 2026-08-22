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
    only: ['products', 'filters', 'sort', 'title', 'pageTitle'],
};
export default function Catalog({ title, description = null, products, filters, sort, searchQuery }) {
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

    const hasActiveFilters = ['attr', 'cf', 'tags', 'price_range', 'price_min', 'price_max', 'in_stock', 'on_sale', 'category']
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

    return (
        <div className="mx-auto max-w-7xl px-4 py-8">
            <div className="flex flex-wrap items-center justify-between gap-4 mb-6">
                <div>
                    <h1 className="font-display text-3xl font-semibold">{title}</h1>
                    {description && <p className="text-ink-700/70 mt-1 max-w-2xl">{description}</p>}
                    <p className="text-sm text-ink-700/60 mt-1">{products.total} item(s)</p>
                </div>
            </div>

            <div className="lg:grid lg:grid-cols-[260px_1fr] lg:gap-8">
                {/* Sidebar filters */}
                <aside className="mb-6 lg:mb-0">
                    <div className="flex items-center justify-between lg:hidden mb-3">
                        <button type="button" onClick={() => setShowFilters(!showFilters)} className="btn-outline py-2 inline-flex items-center gap-1.5"><Icon name="funnel" className="w-4 h-4 shrink-0" /> Filters</button>
                    </div>
                    <div className={showFilters ? 'block' : 'hidden lg:block'}>
                        <div className="flex items-center justify-between mb-3">
                            <h2 className="font-display text-lg tracking-wide">FILTER BY</h2>
                            {hasActiveFilters && (
                                <Link href={clearUrl} className="text-xs text-ink-700/60 hover:text-gold-700">Clear all</Link>
                            )}
                        </div>

                        {filters.length ? filters.map((group) => (
                            <div key={group.label} className="border-t border-ink-100 py-3">
                                <h3 className="text-xs uppercase tracking-wide text-ink-700/60 mb-2">
                                    {group.label}
                                    <span className="block h-0.5 w-8 bg-gold-400 mt-1"></span>
                                </h3>
                                <div className="space-y-1 max-h-64 overflow-y-auto pr-1">
                                    {group.options.map((opt) => (
                                        <label key={opt.value} className="flex items-center gap-2 text-sm py-0.5 cursor-pointer">
                                            <input
                                                type="checkbox"
                                                defaultChecked={opt.checked}
                                                onChange={(e) => applyFilters(group.param, opt.value, e.target.checked)}
                                                className="accent-gold-600"
                                            />
                                            {group.is_color && (
                                                opt.hex === 'multi' ? (
                                                    <span className="w-4 h-4 rounded-full border border-ink-200" style={{ background: 'conic-gradient(red,orange,yellow,green,blue,violet,red)' }} />
                                                ) : opt.hex ? (
                                                    <span className="w-4 h-4 rounded-full border border-ink-200" style={{ background: opt.hex }} />
                                                ) : null
                                            )}
                                            <span>{opt.label}</span>
                                        </label>
                                    ))}
                                </div>
                            </div>
                        )) : (
                            <p className="text-sm text-ink-700/50">No filters configured.</p>
                        )}
                    </div>
                </aside>

                {/* Products */}
                <div>
                    <div className="flex flex-wrap items-center justify-end gap-2 mb-4">
                        <select value={sort} onChange={(e) => applySort(e.target.value)} className="input py-2 w-auto">
                            <option value="new">Newest</option>
                            <option value="popular">Most popular</option>
                            <option value="best_selling">Best selling</option>
                            <option value="price_asc">Price: low to high</option>
                            <option value="price_desc">Price: high to low</option>
                            <option value="name">Name A–Z</option>
                        </select>
                    </div>

                    {products.data.length === 0 ? (
                        <div className="card p-12 text-center text-ink-700/60">
                            <p>No products match these filters.</p>
                            <Link href={urls.shop || '/shop'} className="btn-outline mt-4">Browse all jewelry</Link>
                        </div>
                    ) : (
                        <>
                            <div className="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-8">
                                {products.data.map((p) => <ProductCard key={p.id} product={p} />)}
                            </div>
                            <div className="mt-10">
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
