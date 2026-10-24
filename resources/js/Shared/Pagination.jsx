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
                        className={`grid place-items-center min-w-11 h-11 px-3 rounded-full text-[13px] tabular-nums transition-colors duration-300 ${link.active ? 'bg-ink-900 text-white font-semibold' : 'border border-ink-900/10 text-ink-900 hover:border-ink-900'}`}
                    >
                        {label}
                    </Link>
                );
            })}
        </nav>
    );
}
