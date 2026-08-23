import { Link } from '@inertiajs/react';

// Numbered pagination fed by Laravel's paginator `links` array
// ({url, label, active}); labels arrive as HTML entities (&laquo; etc.).
export default function Pagination({ links = [] }) {
    if (links.length <= 3) return null;

    return (
        <nav className="flex flex-wrap items-center justify-center gap-1.5" aria-label="Pagination">
            {links.map((link, i) => {
                const label = link.label.replace('&laquo;', '‹').replace('&raquo;', '›');
                if (!link.url) {
                    return <span key={i} className="px-3 py-2 text-sm text-ink-700/70">{label}</span>;
                }
                return (
                    <Link
                        key={i}
                        href={link.url}
                        preserveScroll={false}
                        className={`min-w-[38px] px-3 py-2 rounded-lg text-sm text-center transition ${link.active ? 'bg-gold-600 text-white font-semibold' : 'bg-white border border-ink-100 hover:border-gold-400 hover:text-gold-700'}`}
                    >
                        {label}
                    </Link>
                );
            })}
        </nav>
    );
}
